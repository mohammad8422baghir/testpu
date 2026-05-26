<?php
// update_servers.php - GitHub Actions Version

echo "Starting VPN Server Extraction...\n";

$vpngateUrl = "https://www.vpngate.net/en/";
$jsonFile = "servers.json";

function fetchUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $headers = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36",
        "Accept: text/html,application/xhtml+xml",
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function getCountryFromCheckHost($host) {
    $hostPart = explode(':', $host)[0]; 
    $url = "https://check-host.net/ip-info?host=" . $hostPart;
    $html = fetchUrl($url);
    
    if (!$html) return "Unknown";

    $pattern = '/<img[^>]*flag[^>]*>\s*<strong>(.*?)<\/strong>/is';
    if (preg_match($pattern, $html, $matches)) {
        return trim($matches[1]);
    }
    return "Unknown";
}

// 1. Load Existing Servers
$existingServers = [];
$countryCounters = [];

if (file_exists($jsonFile)) {
    $jsonContent = file_get_contents($jsonFile);
    $decoded = json_decode($jsonContent, true);
    if (is_array($decoded)) {
        foreach ($decoded as $srv) {
            $srv['is_new'] = false;
            $existingServers[$srv['id']] = $srv;
            
            $dName = $srv['name'] ?? "";
            if (preg_match('/^(.+) (\d+)$/', $dName, $matches)) {
                $foundCountry = $matches[1];
                $foundNum = (int)$matches[2];
                if (!isset($countryCounters[$foundCountry])) {
                    $countryCounters[$foundCountry] = 0;
                }
                if ($foundNum > $countryCounters[$foundCountry]) {
                    $countryCounters[$foundCountry] = $foundNum;
                }
            }
        }
    }
}

echo "Loaded " . count($existingServers) . " existing servers.\n";

// 2. Fetch New Servers
echo "Fetching VPNGate...\n";
$html = fetchUrl($vpngateUrl);
if (!$html) {
    die("Error: VPNGate fetch failed\n");
}

$pattern = '/([a-zA-Z0-9-]+\.opengw\.net):(\d+)/i';
preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

$finalList = $existingServers;
$newCount = 0;

foreach ($matches as $match) {
    $host = trim($match[1]);
    $port = trim($match[2]);
    
    if (!empty($host) && is_numeric($port)) {
        $id = $host . ':' . $port;
        
        if (!isset($existingServers[$id])) {
            echo "New server found: $id. Fetching country... ";
            $country = getCountryFromCheckHost($id);
            echo "[$country]\n";
            
            if (!isset($countryCounters[$country])) {
                $countryCounters[$country] = 0;
            }
            $countryCounters[$country]++;
            $seqNumber = $countryCounters[$country];
            
            $displayName = "$country $seqNumber";
            
            $finalList[$id] = [
                "id" => $id,
                "name" => $displayName,
                "country" => $country, 
                "host" => $host,
                "port" => (int)$port,
                "username" => "vpn",
                "password" => "vpn",
                "is_new" => true
            ];
            $newCount++;
            
            // جلوگیری از بلاک شدن آی‌پی سرور گیت‌هاب توسط check-host
            sleep(1); 
        }
    }
}

// 3. Sort and Save
$outputList = array_values($finalList);
usort($outputList, function($a, $b) {
    return $b['is_new'] <=> $a['is_new'];
});

file_put_contents($jsonFile, json_encode($outputList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\nProcess Completed! $newCount new servers added.\n";
echo "Total servers: " . count($outputList) . "\n";
?>
