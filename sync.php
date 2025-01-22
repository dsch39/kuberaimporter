<?php
require __DIR__ . '/vendor/autoload.php'; // Load Composer autoloader
use GuzzleHttp\Client;
use Dotenv\Dotenv;

// Enable full error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/config/');
$dotenv->load();

// API configuration
$apiKey = $_ENV['API_KEY'];
$apiSecret = $_ENV['API_SECRET'];
$baseUrl = $_ENV['API_BASE_URL'];

// Logging directory
$logFile = __DIR__ . '/logs/sync.log';
$configFolder = __DIR__ . '/config';
$dataFolder = __DIR__ . '/data';

// Initialize the HTTP client
$client = new Client([
    'base_uri' => $baseUrl,
    'headers' => [
        'Content-Type' => 'application/json',
        'x-api-token' => $apiKey,
    ],
]);

/**
 * Main function for synchronization
 */
function sync() {
    global $client, $apiKey, $apiSecret, $logFile, $configFolder, $dataFolder;

    // Scan config subfolders
    $configFolders = glob($configFolder . '/*', GLOB_ONLYDIR);

    foreach ($configFolders as $folder) {
        $provider = basename($folder);
        $configFile = $folder . '/config.json';
        $providerDataFolder = $dataFolder . '/' . $provider;
        $processedFolder = $providerDataFolder . '/processed';

        // Skip if config file is missing
        if (!file_exists($configFile)) {
            logMessage($logFile, "Skipping $provider: Missing config file.");
            continue;
        }

        // Ensure processed folder exists
        if (!is_dir($processedFolder)) {
            mkdir($processedFolder, 0777, true);
        }

        // Load configuration
        $config = json_decode(file_get_contents($configFile), true);
        if (!$config) {
            logMessage($logFile, "Invalid config file in $provider.");
            continue;
        }


        // Ensure compositeKeyColumns exists in config
        $compositeKeyColumns = $config['compositeKeyColumns'] ?? [];
        if (empty($compositeKeyColumns)) {
            logMessage($logFile, "Missing compositeKeyColumns in $provider config.");
            continue;
        }


        // Process all CSV files in the provider's data folder
        $csvFiles = glob($providerDataFolder . '/*.csv');
        foreach ($csvFiles as $csvFile) {
            logMessage($logFile, "Processing $csvFile for $provider...");

            try {
                // 1. Fetch all assets from all portfolios
                logMessage($logFile, "Fetching all assets from API...");
                $assetsFromAPI = fetchAllAssets($client, $apiKey, $apiSecret);

                // 2. Read current assets from the local CSV file
                logMessage($logFile, "Reading CSV file $csvFile...");
                $assetsFromCSV = readCsv($csvFile, $config['columnMapping']);

                // 3. Compare and synchronize
                $assetsToCreate = []; // To display new assets
                $updatedCount = 0;
                $deletedCount = 0;

                foreach ($assetsFromCSV as $csvAsset) {
                    $compositeKey = generateCompositeKey($csvAsset, $compositeKeyColumns);
                    $matchingAsset = findAssetInAPI($assetsFromAPI, $compositeKey);

                    if ($matchingAsset) {
                        // Asset exists in the API, update its value
                        echo "Match found: CSV Key = $compositeKey, API ID = {$matchingAsset['id']}" . PHP_EOL;
                        // print_r($csvAsset);
                        try {
                            print_r($csvAsset);
                            print_r($matchingAsset);
                            // $value = array('amount' => $csvAsset['value'],
                            //                 'currency' => $csvAsset['currency']);
                            $value = $csvAsset['value'];
                            echo " -> " . $matchingAsset['name'] . "update with value " . print_r($value, true) ." old value: " . $matchingAsset['value']['amount'] . " " . $matchingAsset['value']['currency'] . PHP_EOL;
                            updateAssetValue($client, $apiKey, $apiSecret, $matchingAsset['id'], $value);
                        } catch (Exception $e) {
                            echo "Error updating asset value: " . $e->getMessage() . PHP_EOL;
                        }

                        // Check if composite key is in name but not in description
                        $compositeKeyAttribute = lookupCompositeKeyFoundInDescriptionOrName($matchingAsset);
                        if ($compositeKeyAttribute === 'name') {
                            try {
                                $csvAssetDescriptionColumn = $config['csvAssetDescriptionColumn'];
                                updateAssetAttributes($client, $apiKey, $apiSecret, $matchingAsset['id'], [
                                    'description' => "{id:$compositeKey}",
                                    'name' => $csvAsset[$csvAssetDescriptionColumn],
                                ]);
                            } catch (Exception $e) {
                                echo "Error updating asset attributes: " . $e->getMessage() . PHP_EOL;
                            }
                        }

                        $updatedCount++;
                    } else {
                        // Asset does not exist in the API, add it to the creation list
                        echo "Missing in API: CSV Key = $compositeKey" . PHP_EOL;
                        $csvAssetWithCompositeKey = $csvAsset;
                        $csvAssetWithCompositeKey['compositeKey'] = $compositeKey;

                        $assetsToCreate[] = $csvAssetWithCompositeKey;
                    }

                    sleep(1);
                }

                // Check for assets in the API that are no longer present in the CSV
                foreach ($assetsFromAPI as $apiAsset) {
                    $compositeKey = generateCompositeKey($apiAsset, $compositeKeyColumns);
                    if (!findAssetInCSV($assetsFromCSV, $compositeKey, $compositeKeyColumns)) {
                        try {
                            // Set value to 0 for assets not found in the CSV
                            updateAssetValue($client, $apiKey, $apiSecret, $apiAsset['id'], 0);
                        } catch (Exception $e) {
                            echo "Error updating asset to zero: " . $e->getMessage() . PHP_EOL;
                        }
                        $deletedCount++;
                    }
                }

                // Display new assets
                if (!empty($assetsToCreate)) {
                    logMessage($logFile, "New assets found in $provider:");
                    foreach ($assetsToCreate as $newAsset) {
                        echo "Asset missing in the API: {id:" . $newAsset['compositeKey'] . "} Portfolio: " . $newAsset['clientNumber'] . "} name: " . $newAsset['assetDescription'] . PHP_EOL;
                    }
                }

                logMessage($logFile, "Synchronization completed for $csvFile: $updatedCount updated, $deletedCount deleted.");

                // Move processed CSV to the processed folder with timestamp
                $timestamp = date('Y-m-d-H:i:s');
                $processedFile = $processedFolder . '/' . basename($csvFile, '.csv') . "_$timestamp.csv";
                // rename($csvFile, $processedFile);
                logMessage($logFile, "$csvFile moved to $processedFile");

            } catch (Exception $e) {
                logMessage($logFile, "Error processing $csvFile: " . $e->getMessage());
            }
        }
    }
}


/**
 * Fetch all assets from all portfolios
 */
function fetchAllAssets(Client $client, $apiKey, $apiSecret) {
    $path = '/api/v3/data/portfolio';
    $timestamp = time();
    $signature = generateSignature($apiKey, $apiSecret, $timestamp, 'GET', $path, '');

    try {
        $response = $client->get($path, [
            'headers' => [
                'x-timestamp' => $timestamp,
                'x-signature' => $signature,
            ],
        ]);

        $portfolios = json_decode($response->getBody(), true)['data'];
        $allAssets = [];

        foreach ($portfolios as $portfolio) {
            $portfolioId = $portfolio['id'];
            $portfolioPath = "/api/v3/data/portfolio/$portfolioId";
            $portfolioSignature = generateSignature($apiKey, $apiSecret, $timestamp, 'GET', $portfolioPath, '');

            try {
                $portfolioResponse = $client->get($portfolioPath, [
                    'headers' => [
                        'x-timestamp' => $timestamp,
                        'x-signature' => $portfolioSignature,
                    ],
                ]);

                $portfolioData = json_decode($portfolioResponse->getBody(), true)['data'];
                $allAssets = array_merge($allAssets, $portfolioData['asset']);
            } catch (Exception $e) {
                echo "Error fetching portfolio data: " . $e->getMessage() . PHP_EOL;
            }
        }

        return $allAssets;
    } catch (Exception $e) {
        echo "Error fetching all assets: " . $e->getMessage() . PHP_EOL;
        exit();
        return [];
    }
}


/**
 * Read current assets from the local CSV file
 */
function readCsv(string $filePath, array $columnMapping): array {
    $assets = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $headers = fgetcsv($handle, 0, ';', '"', '\\');
        while (($data = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            if (count($data) === count($headers)) {
                $mappedAsset = [];
                $row = array_combine($headers, $data);
                foreach ($columnMapping as $csvColumn => $apiAttribute) {
                    $mappedAsset[$apiAttribute] = $row[$csvColumn] ?? null;
                }
                $assets[] = $mappedAsset;
            }
        }
        fclose($handle);
    }
    return $assets;
}

/**
 * Generate a composite key based on specific columns
 */
function generateCompositeKey(array $asset, array $compositeKeyColumns): string {
    $compositeKey = implode('|', array_map(fn($key) => trim($asset[$key] ?? ''), $compositeKeyColumns));
    return hash('sha256', $compositeKey); // Hash for anonymization
}

/**
 * Extract the composite key from the description or name attribute
 */
function extractCompositeKeyFromDescriptionOrName(array $asset): ?string {
    // Check the description attribute first
    if (isset($asset['description']) && preg_match('/\{id:([^}]+)\}/', $asset['description'], $matches)) {
        return $matches[1];
    }

    // Check the name attribute next
    if (isset($asset['name']) && preg_match('/\{id:([^}]+)\}/', $asset['name'], $matches)) {
        return $matches[1];
    }

    // return null if not found
    return null;
}

/**
 * Lookup if the composite key was found in the description or name attribute
 */
function lookupCompositeKeyFoundInDescriptionOrName(array $asset): ?string {
    // Check the description attribute first
    if (isset($asset['description']) && preg_match('/\{id:([^}]+)\}/', $asset['description'], $matches)) {
        return 'description';
    }

    // Check the name attribute next
    if (isset($asset['name']) && preg_match('/\{id:([^}]+)\}/', $asset['name'], $matches)) {
        return 'name';
    }

    // return null if not found
    return null;
}

/**
 * Find an asset in the API's asset list
 */
function findAssetInAPI(array $assetsFromAPI, string $compositeKey): ?array {
    foreach ($assetsFromAPI as $asset) {
        $apiCompositeKey = extractCompositeKeyFromDescriptionOrName($asset);
        if ($apiCompositeKey === $compositeKey) {
            return $asset;
        }
    }
    return null;
}

/**
 * Check if an asset exists in the CSV list
 */
function findAssetInCSV(array $assetsFromCSV, string $compositeKey, array $compositeKeyColumns): bool {
    foreach ($assetsFromCSV as $csvAsset) {
        if (generateCompositeKey($csvAsset, $compositeKeyColumns) === $compositeKey) {
            return true;
        }
    }
    return false;
}

/**
 * Update an asset's value
 */
function updateAssetValue(Client $client, $apiKey, $apiSecret, $itemId, $value) {
    $path = "/api/v3/data/item/$itemId";
    $timestamp = time();
    $body = json_encode(['value' => $value]);
    $signature = generateSignature($apiKey, $apiSecret, $timestamp, 'POST', $path, $body);

    try {
        $client->post($path, [
            'headers' => [
                'x-timestamp' => $timestamp,
                'x-signature' => $signature,
            ],
            'body' => $body,
        ]);
    } catch (Exception $e) {
        echo "Error updating asset value: " . $e->getMessage() . PHP_EOL;
    }
}

/**
 * Update asset attributes
 */
function updateAssetAttributes(Client $client, $apiKey, $apiSecret, $itemId, array $attributes) {
    $path = "/api/v3/data/item/$itemId";
    $timestamp = time();
    $body = json_encode($attributes);
    $signature = generateSignature($apiKey, $apiSecret, $timestamp, 'POST', $path, $body);

    try {
        $response = $client->post($path, [
            'headers' => [
                'x-timestamp' => $timestamp,
                'x-signature' => $signature,
            ],
            'body' => $body,
        ]);
        if ($response->getStatusCode() >= 400) {
            echo "Error occurred: " . $response->getBody() . PHP_EOL;
        }
    } catch (Exception $e) {
        echo "Error updating asset attributes: " . $e->getMessage() . PHP_EOL;
    }
}

/**
 * Generate a signature for API authentication
 */
function generateSignature($apiKey, $apiSecret, $timestamp, $method, $path, $body) {
    $data = $apiKey . $timestamp . $method . $path . $body;
    return hash_hmac('sha256', $data, $apiSecret);
}

/**
 * Log messages to a file
 */
function logMessage($logFile, $message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}



// Start the synchronization process
sync();
