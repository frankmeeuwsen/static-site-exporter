<?php
/**
 * Test script for GitHub upload functionality
 * Run this from command line: php test-github-upload.php
 *
 * This script tests the chunked GitHub upload process without WordPress
 */

// Configuration - UPDATE THESE VALUES
$export_dir = '/Users/monique/Local Sites/modub/app/public/wp-content/static-export';
$github_token = 'YOUR_GITHUB_TOKEN_HERE'; // Replace with actual token
$github_repo = 'mdubbelm/modub-static-site';
$github_branch = 'main';

// Constants matching plugin
define('CHUNK_SIZE', 15);
define('MAX_FILE_SIZE', 10485760); // 10MB
define('API_TIMEOUT', 60);

/**
 * Test 1: File scanning performance
 */
function test_file_scanning($export_dir) {
    echo "\n=== TEST 1: File Scanning Performance ===\n";

    $start_time = microtime(true);
    $start_memory = memory_get_usage();

    $files = get_all_files_efficient($export_dir);

    $end_time = microtime(true);
    $end_memory = memory_get_usage();

    $elapsed = round($end_time - $start_time, 2);
    $memory_used = round(($end_memory - $start_memory) / 1024 / 1024, 2);

    echo "Files found: " . count($files) . "\n";
    echo "Time taken: {$elapsed}s\n";
    echo "Memory used: {$memory_used}MB\n";
    echo "Peak memory: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . "MB\n";

    // Check for large files
    $large_files = 0;
    $total_size = 0;

    foreach ($files as $file) {
        $size = filesize($file);
        $total_size += $size;
        if ($size > MAX_FILE_SIZE) {
            $large_files++;
            echo "⚠ Large file will be skipped: " . basename($file) . " (" . round($size / 1024 / 1024, 2) . "MB)\n";
        }
    }

    echo "Total size: " . round($total_size / 1024 / 1024, 2) . "MB\n";
    echo "Large files (>10MB): $large_files\n";

    return [
        'success' => true,
        'files' => $files,
        'total_files' => count($files),
        'elapsed' => $elapsed,
        'memory' => $memory_used
    ];
}

/**
 * Test 2: Chunk processing simulation
 */
function test_chunk_processing($files) {
    echo "\n=== TEST 2: Chunk Processing Simulation ===\n";

    $total_files = count($files);
    $total_chunks = (int) ceil($total_files / CHUNK_SIZE);

    echo "Total files: $total_files\n";
    echo "Chunk size: " . CHUNK_SIZE . "\n";
    echo "Total chunks: $total_chunks\n";

    // Estimate time
    $estimated_seconds_per_chunk = 20; // Conservative estimate
    $estimated_total_seconds = $total_chunks * $estimated_seconds_per_chunk;
    $estimated_minutes = round($estimated_total_seconds / 60);

    echo "Estimated time per chunk: {$estimated_seconds_per_chunk}s\n";
    echo "Estimated total time: {$estimated_minutes} minutes\n";

    // Simulate chunk processing
    $start_time = microtime(true);

    for ($i = 0; $i < min(3, $total_chunks); $i++) {
        $chunk_start = $i * CHUNK_SIZE;
        $chunk_files = array_slice($files, $chunk_start, CHUNK_SIZE);

        echo "\nProcessing chunk " . ($i + 1) . "/" . $total_chunks . ":\n";
        echo "  Files in chunk: " . count($chunk_files) . "\n";

        $chunk_time_start = microtime(true);
        $chunk_size = 0;

        foreach ($chunk_files as $file) {
            $size = filesize($file);
            $chunk_size += $size;

            // Simulate blob creation (base64 encoding)
            $content = file_get_contents($file);
            $encoded = base64_encode($content);
            unset($content, $encoded);
        }

        $chunk_time = round(microtime(true) - $chunk_time_start, 2);

        echo "  Chunk size: " . round($chunk_size / 1024 / 1024, 2) . "MB\n";
        echo "  Processing time: {$chunk_time}s\n";
        echo "  Memory: " . round(memory_get_usage() / 1024 / 1024, 2) . "MB\n";

        gc_collect_cycles();
    }

    $elapsed = round(microtime(true) - $start_time, 2);
    echo "\nSample processing completed in {$elapsed}s\n";

    return [
        'success' => true,
        'total_chunks' => $total_chunks,
        'estimated_minutes' => $estimated_minutes
    ];
}

/**
 * Test 3: GitHub API connectivity (without uploading)
 */
function test_github_connectivity($token, $repo, $branch) {
    echo "\n=== TEST 3: GitHub API Connectivity ===\n";

    if ($token === 'YOUR_GITHUB_TOKEN_HERE') {
        echo "⚠ GitHub token not configured. Skipping API tests.\n";
        echo "To test API connectivity, edit this file and add your GitHub token.\n";
        return ['success' => false, 'reason' => 'Token not configured'];
    }

    // Test 1: Verify credentials
    echo "Testing authentication...\n";
    $ch = curl_init('https://api.github.com/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'User-Agent: WordPress-Static-Exporter-Test',
            'Accept: application/vnd.github.v3+json'
        ],
        CURLOPT_TIMEOUT => API_TIMEOUT
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $user_data = json_decode($response, true);
        echo "✓ Authentication successful\n";
        echo "  User: " . ($user_data['login'] ?? 'Unknown') . "\n";
    } else {
        echo "✗ Authentication failed (HTTP $http_code)\n";
        return ['success' => false, 'reason' => 'Authentication failed'];
    }

    // Test 2: Check repository access
    echo "\nTesting repository access...\n";
    $ch = curl_init("https://api.github.com/repos/$repo/git/refs/heads/$branch");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'User-Agent: WordPress-Static-Exporter-Test',
            'Accept: application/vnd.github.v3+json'
        ],
        CURLOPT_TIMEOUT => API_TIMEOUT
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $ref_data = json_decode($response, true);
        $commit_sha = $ref_data['object']['sha'] ?? null;
        echo "✓ Repository access successful\n";
        echo "  Current commit: " . substr($commit_sha, 0, 7) . "\n";
    } else {
        echo "✗ Repository access failed (HTTP $http_code)\n";
        return ['success' => false, 'reason' => 'Repository access failed'];
    }

    // Test 3: Check rate limits
    echo "\nChecking GitHub API rate limits...\n";
    $ch = curl_init('https://api.github.com/rate_limit');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'User-Agent: WordPress-Static-Exporter-Test'
        ],
        CURLOPT_TIMEOUT => API_TIMEOUT
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $rate_data = json_decode($response, true);
    $core_limit = $rate_data['resources']['core']['limit'] ?? 0;
    $core_remaining = $rate_data['resources']['core']['remaining'] ?? 0;
    $reset_time = $rate_data['resources']['core']['reset'] ?? 0;

    echo "  Rate limit: $core_remaining / $core_limit remaining\n";
    echo "  Resets at: " . date('Y-m-d H:i:s', $reset_time) . "\n";

    if ($core_remaining < 100) {
        echo "⚠ Warning: Low rate limit remaining. Upload may fail.\n";
    }

    return [
        'success' => true,
        'rate_remaining' => $core_remaining,
        'rate_limit' => $core_limit
    ];
}

/**
 * Test 4: Memory and timeout stress test
 */
function test_memory_timeout() {
    echo "\n=== TEST 4: Memory and Timeout Analysis ===\n";

    echo "Current PHP settings:\n";
    echo "  memory_limit: " . ini_get('memory_limit') . "\n";
    echo "  max_execution_time: " . ini_get('max_execution_time') . "\n";
    echo "  upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
    echo "  post_max_size: " . ini_get('post_max_size') . "\n";

    echo "\nMemory usage:\n";
    echo "  Current: " . round(memory_get_usage() / 1024 / 1024, 2) . "MB\n";
    echo "  Peak: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . "MB\n";

    $memory_limit_bytes = return_bytes(ini_get('memory_limit'));
    $available_memory = round(($memory_limit_bytes - memory_get_usage()) / 1024 / 1024, 2);
    echo "  Available: {$available_memory}MB\n";

    if ($available_memory < 100) {
        echo "⚠ Warning: Low available memory. Consider increasing memory_limit.\n";
    }

    return ['success' => true];
}

/**
 * Helper: Get all files efficiently
 */
function get_all_files_efficient($dir) {
    $files = [];

    if (!file_exists($dir)) return $files;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }

        if (count($files) % 500 === 0) {
            gc_collect_cycles();
        }
    }

    return $files;
}

/**
 * Helper: Convert PHP memory limit to bytes
 */
function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int) $val;

    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }

    return $val;
}

/**
 * Main test runner
 */
function run_all_tests($export_dir, $token, $repo, $branch) {
    echo "╔════════════════════════════════════════════════════════╗\n";
    echo "║  Static Site Exporter - GitHub Upload Test Suite      ║\n";
    echo "╚════════════════════════════════════════════════════════╝\n";
    echo "\nExport directory: $export_dir\n";
    echo "Repository: $repo\n";
    echo "Branch: $branch\n";

    $results = [];

    // Test 1: File scanning
    $results['file_scanning'] = test_file_scanning($export_dir);

    if (!$results['file_scanning']['success']) {
        echo "\n✗ File scanning failed. Cannot continue.\n";
        return;
    }

    $files = $results['file_scanning']['files'];

    // Test 2: Chunk processing
    $results['chunk_processing'] = test_chunk_processing($files);

    // Test 3: GitHub connectivity
    $results['github_connectivity'] = test_github_connectivity($token, $repo, $branch);

    // Test 4: Memory/timeout
    $results['memory_timeout'] = test_memory_timeout();

    // Summary
    echo "\n\n╔════════════════════════════════════════════════════════╗\n";
    echo "║  TEST SUMMARY                                          ║\n";
    echo "╚════════════════════════════════════════════════════════╝\n\n";

    echo "Total files to upload: " . $results['file_scanning']['total_files'] . "\n";
    echo "Total chunks: " . $results['chunk_processing']['total_chunks'] . "\n";
    echo "Estimated upload time: " . $results['chunk_processing']['estimated_minutes'] . " minutes\n";
    echo "File scanning time: " . $results['file_scanning']['elapsed'] . "s\n";
    echo "Memory usage: " . $results['file_scanning']['memory'] . "MB\n";

    if ($results['github_connectivity']['success']) {
        echo "GitHub API: ✓ Connected\n";
        echo "Rate limit remaining: " . $results['github_connectivity']['rate_remaining'] . "\n";
    } else {
        echo "GitHub API: ✗ Not tested (configure token to test)\n";
    }

    // Recommendations
    echo "\n\n╔════════════════════════════════════════════════════════╗\n";
    echo "║  RECOMMENDATIONS                                       ║\n";
    echo "╚════════════════════════════════════════════════════════╝\n\n";

    $file_count = $results['file_scanning']['total_files'];
    $chunk_count = $results['chunk_processing']['total_chunks'];

    if ($file_count > 2000) {
        echo "⚠ Large site: Consider optimizing chunk size or batch processing\n";
    }

    if ($results['chunk_processing']['estimated_minutes'] > 120) {
        echo "⚠ Long upload time: Consider increasing CHUNK_SIZE for faster processing\n";
        echo "  Current: " . CHUNK_SIZE . " files/chunk\n";
        echo "  Recommended: 20-25 files/chunk for large sites\n";
    }

    if ($results['github_connectivity']['success'] &&
        $results['github_connectivity']['rate_remaining'] < $chunk_count * 2) {
        echo "⚠ Rate limit warning: You may hit rate limits during upload\n";
        echo "  Each chunk requires 1 API call per file + 3 calls for finalization\n";
        echo "  Total API calls needed: ~" . ($file_count + 3) . "\n";
    }

    echo "\n✓ Test suite completed\n\n";
}

// Run tests
if (!file_exists($export_dir)) {
    echo "Error: Export directory not found: $export_dir\n";
    exit(1);
}

run_all_tests($export_dir, $github_token, $github_repo, $github_branch);
