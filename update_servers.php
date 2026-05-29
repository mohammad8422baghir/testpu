<?php
// update_servers.php - GitHub Actions Version (3 Methods)

date_default_timezone_set('UTC'); // تنظیم زمان به وقت جهانی
echo "Starting VPN Server Extraction...\n";

$vpngateUrl = "https://www.vpngate.net/en/";

// --- نام فایل‌ها و پوشه‌ها ---
$masterJsonFile = "servers.json"; // روش دوم
$latestDailyFile = "latest_daily.json"; // روش اول (لینک ثابت)
$latestNewOnlyFile = "latest_new_only.json"; // روش سوم (لینک ثابت)

$dirDaily = "daily_snapshots";
$dirNewOnly = "new_only_batches";

// ساخت پوشه‌ها اگر وجود ندارند
if (!is_dir($dirDaily)) mkdir($dirDaily, 0777, true);
if (!is_dir($dirNewOnly)) mkdir($dirNewOnly, 0777, true);

// --- توابع کمکی ---
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

// --- 1. بارگذاری دیتابیس اصلی (روش دوم) برای مقایسه ---
$masterServers = [];
$countryCounters = [];
$countryCache = []; // کش برای جلوگیری از بن شدن توسط سایت تشخیص آی‌پی

if (file_exists($masterJsonFile)) {
    $jsonContent = file_get_contents($masterJsonFile);
    $decoded = json_decode($jsonContent, true);
    if (is_array($decoded)) {
        foreach ($decoded as $srv) {
            $srv['is_new'] = false; // قدیمی‌ها دیگر جدید نیستند
            $masterServers[$srv['id']] = $srv;
            
            // ذخیره کشور در کش تا دوباره برای این آی‌پی درخواست نزنیم
            $countryCache[explode(':', $srv['id'])[0]] = $srv['country'];
            
            // شمارش شماره کشورها (مثلا Japan 15)
            $dName = $srv['name'] ?? "";
            if (preg_match('/^(.+) (\d+)$/', $dName, $matches)) {
                $foundCountry = $matches[1];
                $foundNum = (int)$matches[2];
                if (!isset($countryCounters[$foundCountry])) $countryCounters[$foundCountry] = 0;
                if ($foundNum > $countryCounters[$foundCountry]) $countryCounters[$foundCountry] = $foundNum;
            }
        }
    }
}
echo "Loaded " . count($masterServers) . " master servers from history.\n";

// --- 2. دریافت سرورهای فعلی از سایت ---
echo "Fetching VPNGate...\n";
$html = fetchUrl($vpngateUrl);
if (!$html) die("Error: VPNGate fetch failed\n");

$pattern = '/([a-zA-Z0-9-]+\.opengw\.net):(\d+)/i';
preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

$dailyList = []; // برای روش اول
$newOnlyList = []; // برای روش سوم
$newCount = 0;

foreach ($matches as $match) {
    $host = trim($match[1]);
    $port = trim($match[2]);
    
    if (!empty($host) && is_numeric($port)) {
        $id = $host . ':' . $port;
        
        // پیدا کردن کشور (یا از کش یا از اینترنت)
        $country = "Unknown";
        if (isset($countryCache[$host])) {
            $country = $countryCache[$host];
        } else {
            echo "Fetching country for new IP: $host ... ";
            $country = getCountryFromCheckHost($id);
            $countryCache[$host] = $country;
            echo "[$country]\n";
            sleep(1); // جلوگیری از بلاک شدن توسط check-host
        }

        // آیا این سرور کلاً برای ما جدید است؟ (مقایسه با مستر لیست)
        $isBrandNew = !isset($masterServers[$id]);

        if ($isBrandNew) {
            // محاسبه شماره جدید
            if (!isset($countryCounters[$country])) $countryCounters[$country] = 0;
            $countryCounters[$country]++;
            $seqNumber = $countryCounters[$country];
            $displayName = "$country $seqNumber";
            
            $serverObj = [
                "id" => $id,
                "name" => $displayName,
                "country" => $country, 
                "host" => $host,
                "port" => (int)$port,
                "username" => "vpn",
                "password" => "vpn",
                "is_new" => true
            ];
            
            // اضافه به مستر لیست (روش دوم)
            $masterServers[$id] = $serverObj;
            // اضافه به لیست فقط جدیدها (روش سوم)
            $newOnlyList[] = $serverObj;
            $newCount++;
        } else {
            // سرور تکراری است، آن را از مستر لیست می‌گیریم تا نامش عوض نشود
            $serverObj = $masterServers[$id];
        }
        
        // اضافه به لیست اسنپ‌شات روزانه (روش اول)
        // اینجا برامون مهم نیست جدیده یا نه، هرچی تو سایت هست رو میریزیم
        $dailyList[] = $serverObj;
    }
}

// --- 3. ذخیره‌سازی فایل‌ها بر اساس ۳ روش درخواست شده ---

// تابع مرتب‌سازی (جدیدها بالا)
function sortServers(&$list) {
    usort($list, function($a, $b) {
        return $b['is_new'] <=> $a['is_new'];
    });
}

// === روش اول: Daily Snapshot (تمام سرورهای پیدا شده در این لحظه) ===
sortServers($dailyList);
$dailyFileName = $dirDaily . "/" . date('Y-m-d') . ".json";
file_put_contents($dailyFileName, json_encode($dailyList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($latestDailyFile, json_encode($dailyList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Method 1: Saved " . count($dailyList) . " servers to $dailyFileName and $latestDailyFile\n";

// === روش دوم: Master Cumulative (پکیج کامل بدون تکراری) ===
$masterOutput = array_values($masterServers);
sortServers($masterOutput);
file_put_contents($masterJsonFile, json_encode($masterOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Method 2: Saved " . count($masterOutput) . " servers to $masterJsonFile\n";

// === روش سوم: New Only (فقط سرورهایی که تو این 2 ساعت جدید بودن) ===
sortServers($newOnlyList);
$newOnlyFileName = $dirNewOnly . "/" . date('Y-m-d_H-i') . ".json";
// اگر سرور جدیدی بود فایل زمان‌دار بساز
if (count($newOnlyList) > 0) {
    file_put_contents($newOnlyFileName, json_encode($newOnlyList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
// اما فایل latest_new_only را همیشه بساز تا اپلیکیشن بتونه بخونه (حتی اگه خالی باشه)
file_put_contents($latestNewOnlyFile, json_encode($newOnlyList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Method 3: Saved " . count($newOnlyList) . " NEW servers to $newOnlyFileName and $latestNewOnlyFile\n";

echo "\nProcess Completed Successfully!\n";
?>
