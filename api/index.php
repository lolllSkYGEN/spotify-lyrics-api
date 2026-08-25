<?php

require_once __DIR__ . '/../vendor/autoload.php';

use SpotifyLyricsApi\Spotify;
use SpotifyLyricsApi\SpotifyException;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

$trackid = $_GET['trackid'] ?? null;
$url = $_GET['url'] ?? null;
$q = $_GET['q'] ?? null;
$title = $_GET['title'] ?? null;
$artist = $_GET['artist'] ?? null;
$format = $_GET['format'] ?? null;

$re = '~[\bhttps://open.\b]*spotify[\b.com\b]*[/:]*track[/:]*([A-Za-z0-9]+)~';

if (!$trackid && !$url && !$q && (!$title || !$artist)) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Missing parameters!']);
    return;
}

if ($url) {
    preg_match($re, $url, $matches, PREG_OFFSET_CAPTURE, 0);
    $trackid = $matches[1][0];
}

$spotify = new Spotify(getenv('SP_DC'));
$debug_info = [];

// 1. Попытка поиска через Spotify, если переданы артист и название
if ($title && $artist) {
    try {
        $spotify->checkTokenExpire();
        $query = "track:" . $title . " artist:" . $artist;
        $trackid = $spotify->searchTrack($query);
        if (!$trackid) {
            $debug_info[] = "Spotify search returned null: " . json_encode($spotify->search_error);
        }
    } catch (Exception $e) {
        $debug_info[] = "Spotify search exception: " . $e->getMessage();
    }
} elseif ($q) {
    try {
        $spotify->checkTokenExpire();
        $trackid = $spotify->searchTrack($q);
        if (!$trackid) {
            $debug_info[] = "Spotify legacy search returned null: " . json_encode($spotify->search_error);
        }
    } catch (Exception $e) {
        $debug_info[] = "Spotify legacy search exception: " . $e->getMessage();
    }
}

// 2. Если нашли Track ID в Spotify — отдаем текст оттуда
if ($trackid) {
    try {
        $spotify->checkTokenExpire();
        $lyricsData = $spotify->getLyrics(track_id: $trackid);
        $lyricsLines = $lyricsData['lyrics']['lines'];
        
        $lines = match ($format) {
            'lrc' => $spotify->getLrcLyrics($lyricsLines),
            'srt' => $spotify->getSrtLyrics($lyricsLines),
            'raw' => $spotify->getRawLyrics($lyricsLines),
            default => $lyricsLines,
        };
        
        echo json_encode([
            'error' => false,
            'source' => 'spotify',
            'syncType' => $lyricsData['lyrics']['syncType'],
            'lines' => $lines
        ]);
        return;
    } catch (Exception $e) {
        $debug_info[] = "Spotify getLyrics exception: " . $e->getMessage();
    }
}

// 3. Резервный поиск через LRCLIB (если лимиты Spotify превышены)
if ($title && $artist) {
    $lrclib_data = fetch_lrclib($artist, $title);
    if ($lrclib_data) {
        $syncType = 'UNSYNCED';
        $lines = [];
        
        if (!empty($lrclib_data['syncedLyrics'])) {
            $lines = parse_lrc($lrclib_data['syncedLyrics']);
            $syncType = 'LINE_SYNCED';
        } elseif (!empty($lrclib_data['plainLyrics'])) {
            $lines = parse_plain_lyrics($lrclib_data['plainLyrics'], $lrclib_data['duration']);
            $syncType = 'LINE_SYNCED'; // Считаем синхронизированным за счет автораспределения
        }
        
        if (!empty($lines)) {
            echo json_encode([
                'error' => false,
                'source' => 'lrclib',
                'syncType' => $syncType,
                'lines' => $lines
            ]);
            return;
        }
    } else {
        $debug_info[] = "LRCLIB query returned empty results.";
    }
}

// 4. Ошибка 404, если ничего не помогло
http_response_code(404);
echo json_encode([
    'error' => true,
    'message' => 'Track not found on Spotify or LRCLIB.',
    'debug' => $debug_info
]);

function fetch_lrclib($artist, $title) {
    $url = 'https://lrclib.net/api/get?' . http_build_query([
        'artist_name' => $artist,
        'track_name' => $title
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    $http_code = $info['http_code'];
    
    if ($http_code === 200) {
        return json_decode($response, true);
    }
    return null;
}

function parse_lrc($lrc_string) {
    $lines = [];
    foreach (explode("\n", $lrc_string) as $line) {
        $line = trim($line);
        if (preg_match('/^\[(\d+):(\d+)(?:\.(\d+))?\](.*)$/', $line, $matches)) {
            $min = (int)$matches[1];
            $sec = (int)$matches[2];
            $centisec = isset($matches[3]) ? (int)$matches[3] : 0;
            if (strlen($matches[3]) === 3) {
                $ms = $centisec;
            } else {
                $ms = $centisec * 10;
            }
            $startTimeMs = ($min * 60 + $sec) * 1000 + $ms;
            $words = trim($matches[4]);
            $lines[] = [
                'startTimeMs' => (string)$startTimeMs,
                'words' => $words,
                'syllables' => [],
                'endTimeMs' => '0',
                'transliteratedWords' => ''
            ];
        }
    }
    return $lines;
}

function parse_plain_lyrics($plain_string, $duration_seconds) {
    $lines = [];
    $raw_lines = array_filter(array_map('trim', explode("\n", $plain_string)));
    $count = count($raw_lines);
    if ($count === 0) return [];
    
    $duration_ms = ($duration_seconds > 0 ? $duration_seconds : 180) * 1000;
    $line_duration = $duration_ms / $count;
    
    $i = 0;
    foreach ($raw_lines as $line_text) {
        $startTimeMs = round($i * $line_duration);
        $lines[] = [
            'startTimeMs' => (string)$startTimeMs,
            'words' => $line_text,
            'syllables' => [],
            'endTimeMs' => '0',
            'transliteratedWords' => ''
        ];
        $i++;
    }
    return $lines;
}
