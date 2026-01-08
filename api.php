<?php
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$baseDir = __DIR__ . '/content';

// Helper function to scan directory for specific extensions (Case Insensitive)
function getFiles($dir) {
    if (!is_dir($dir)) return [];
    
    $files = [];
    $allowedVideo = ['mp4', 'webm', 'mov', 'mkv'];
    $allowedImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedText = ['txt', 'md'];
    
    // Using DirectoryIterator for better performance and control
    $iterator = new DirectoryIterator($dir);
    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isDot()) continue;
        
        if ($fileinfo->isFile()) {
            $ext = strtolower($fileinfo->getExtension());
            $type = 'unknown';
            
            if (in_array($ext, $allowedVideo)) $type = 'video';
            elseif (in_array($ext, $allowedImage)) $type = 'image';
            elseif (in_array($ext, $allowedText)) $type = 'text';
            else continue; // Skip unsupported files
            
            // Get relative path for frontend
            // Normalize path to use forward slashes
            $fullPath = str_replace('\\', '/', $fileinfo->getPathname());
            $scriptDir = str_replace('\\', '/', __DIR__);
            
            // Remove script directory from full path to get relative path
            // We adding 1 to length to account for the trailing slash
            $relativePath = substr($fullPath, strlen($scriptDir) + 1);
            
            $files[] = [
                'name' => $fileinfo->getFilename(),
                'path' => $relativePath,
                'type' => $type,
                'date' => date("d M Y, h:i A", $fileinfo->getMTime())
            ];
        }
    }
    
    // Sort files naturally
    usort($files, function($a, $b) {
        return strnatcasecmp($a['name'], $b['name']);
    });
    
    return $files;
}

// Function to find the "Final" video preview
function getFinalPreview($categoryPath) {
    $finalPath = $categoryPath . '/Final';
    if (is_dir($finalPath)) {
        $files = getFiles($finalPath);
        foreach ($files as $f) {
            if ($f['type'] === 'video') {
                return ['path' => $f['path'], 'date' => $f['date']];
            }
        }
    }
    return null;
}

$mode = $_GET['mode'] ?? '';

try {
    if (!is_dir($baseDir)) {
        throw new Exception("Content directory not found.");
    }

    if ($mode === 'categories') {
        header("Content-Type: application/json; charset=UTF-8");
        
        $categories = [];
        $dirs = new DirectoryIterator($baseDir);
        
        foreach ($dirs as $fileinfo) {
            if (!$fileinfo->isDot() && $fileinfo->isDir()) {
                $previewData = getFinalPreview($fileinfo->getPathname());
                $item = [
                    'name' => $fileinfo->getFilename(),
                    'preview' => $previewData ? $previewData['path'] : null,
                    'date' => $previewData ? $previewData['date'] : null
                ];
                $categories[] = $item;
            }
        }
        
        usort($categories, function($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        
        echo json_encode(['status' => 'success', 'data' => $categories]);

    } elseif ($mode === 'projects') {
        header("Content-Type: application/json; charset=UTF-8");

        $category = $_GET['category'] ?? '';
        if (!$category) throw new Exception("Category is required.");
        
        // Prevent directory traversal
        $safeCategory = basename($category);
        $categoryPath = $baseDir . '/' . $safeCategory;
        
        if (!is_dir($categoryPath)) throw new Exception("Category not found.");
        
        $projects = [];
        $dirs = new DirectoryIterator($categoryPath);
        
        foreach ($dirs as $fileinfo) {
            if (!$fileinfo->isDot() && $fileinfo->isDir()) {
                $folderName = $fileinfo->getFilename();
                $projectFiles = getFiles($fileinfo->getPathname());

                // Check for tags.txt
                $tags = [];
                $tagsFile = $fileinfo->getPathname() . '/tags.txt';
                if (file_exists($tagsFile)) {
                    $tagsContent = file_get_contents($tagsFile);
                    $rawTags = explode(',', $tagsContent);
                    $tags = array_map('trim', $rawTags);
                    $tags = array_filter($tags); // Remove empty values
                }
                
                $projects[] = [
                    'name' => $folderName,
                    'files' => $projectFiles,
                    'tags' => array_values($tags)
                ];
            }
        }
        
        usort($projects, function($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        
        echo json_encode(['status' => 'success', 'data' => $projects]);

    } elseif ($mode === 'download') {
        $category = $_GET['category'] ?? '';
        $project = $_GET['project'] ?? '';

        if (!$category || !$project) throw new Exception("Category and Project required.");

        if (!extension_loaded('zip')) {
            throw new Exception("ZIP PHP extension not loaded.");
        }

        $projectPath = $baseDir . '/' . $category . '/' . $project;
        if (!is_dir($projectPath)) throw new Exception("Project not found.");

        $zipFile = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception("Cannot create zip file.");
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($projectPath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();
        
        // Clean output buffer to prevent corruption
        if (ob_get_length()) ob_clean(); 
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($project) . '.zip"');
        header('Content-Length: ' . filesize($zipFile));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        
        readfile($zipFile);
        unlink($zipFile);
        exit;

    } else {
        header("Content-Type: application/json; charset=UTF-8");
        throw new Exception("Invalid mode.");
    }

} catch (Exception $e) {
    if ($mode !== 'download') {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } else {
        echo "Error: " . $e->getMessage();
    }
}
