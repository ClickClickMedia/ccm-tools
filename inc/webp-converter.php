<?php
/**
 * WebP Image Converter
 * 
 * Converts uploaded images to WebP format and serves them on the frontend.
 * 
 * @package CCM_Tools
 * @since 7.3.0
 */

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check which image processing extensions are available
 * 
 * @return array Array of available extensions with their capabilities
 */
function ccm_tools_webp_get_available_extensions() {
    $extensions = array();
    
    // Check GD extension
    if (extension_loaded('gd')) {
        $gd_info = gd_info();
        $extensions['gd'] = array(
            'name' => 'GD Library',
            'version' => isset($gd_info['GD Version']) ? $gd_info['GD Version'] : 'Unknown',
            'webp_support' => isset($gd_info['WebP Support']) && $gd_info['WebP Support'],
            'jpeg_support' => isset($gd_info['JPEG Support']) && $gd_info['JPEG Support'],
            'png_support' => isset($gd_info['PNG Support']) && $gd_info['PNG Support'],
            'gif_support' => isset($gd_info['GIF Read Support']) && $gd_info['GIF Read Support'],
            'priority' => 2
        );
    }
    
    // Check Imagick extension
    if (extension_loaded('imagick') && class_exists('Imagick')) {
        $imagick = new Imagick();
        $formats = $imagick->queryFormats();
        $extensions['imagick'] = array(
            'name' => 'ImageMagick',
            'version' => Imagick::getVersion()['versionString'] ?? 'Unknown',
            'webp_support' => in_array('WEBP', $formats),
            'jpeg_support' => in_array('JPEG', $formats),
            'png_support' => in_array('PNG', $formats),
            'gif_support' => in_array('GIF', $formats),
            'priority' => 1 // Preferred over GD
        );
    }
    
    // Check VIPS extension (if available)
    if (extension_loaded('vips') || function_exists('vips_image_new_from_file')) {
        $extensions['vips'] = array(
            'name' => 'libvips',
            'version' => defined('VIPS_VERSION') ? VIPS_VERSION : 'Unknown',
            'webp_support' => true, // VIPS generally supports WebP
            'jpeg_support' => true,
            'png_support' => true,
            'gif_support' => true,
            'priority' => 0 // Highest priority - fastest
        );
    }
    
    return $extensions;
}

/**
 * Check if WebP conversion is possible
 * 
 * @return bool True if at least one extension supports WebP
 */
function ccm_tools_webp_is_available() {
    $extensions = ccm_tools_webp_get_available_extensions();
    
    foreach ($extensions as $ext) {
        if (!empty($ext['webp_support'])) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get the best available extension for WebP conversion
 * 
 * @return string|false Extension name or false if none available
 */
function ccm_tools_webp_get_best_extension() {
    $extensions = ccm_tools_webp_get_available_extensions();
    $best = null;
    $best_priority = PHP_INT_MAX;
    
    foreach ($extensions as $name => $ext) {
        if (!empty($ext['webp_support']) && $ext['priority'] < $best_priority) {
            $best = $name;
            $best_priority = $ext['priority'];
        }
    }
    
    return $best;
}

/**
 * Get WebP converter settings
 * 
 * @return array Settings array
 */
function ccm_tools_webp_get_settings() {
    $defaults = array(
        'enabled' => false,
        'quality' => 85, // Default to 85 for near-lossless quality
        'convert_on_upload' => true,
        'serve_webp' => true,
        'convert_on_demand' => false,
        'use_picture_tags' => false,
        'keep_originals' => true,
        'convert_existing' => false,
        'exclude_sizes' => array(),
        'preferred_extension' => 'auto'
    );
    
    $settings = get_option('ccm_tools_webp_settings', array());
    return wp_parse_args($settings, $defaults);
}

/**
 * Save WebP converter settings
 * 
 * @param array $settings Settings to save
 * @return bool Success
 */
function ccm_tools_webp_save_settings($settings) {
    return update_option('ccm_tools_webp_settings', $settings);
}

/**
 * Convert an image to WebP format
 * 
 * @param string $source_path Path to source image
 * @param string $dest_path Path to destination WebP file (optional)
 * @param int $quality Compression quality (1-100)
 * @param string $extension Which extension to use (auto, gd, imagick, vips)
 * @return array Result with success status, path, and file sizes
 */
function ccm_tools_webp_convert_image($source_path, $dest_path = '', $quality = 82, $extension = 'auto') {
    $result = array(
        'success' => false,
        'message' => '',
        'source_path' => $source_path,
        'dest_path' => '',
        'source_size' => 0,
        'dest_size' => 0,
        'savings_percent' => 0,
        'extension_used' => ''
    );
    
    // Validate source file
    if (!file_exists($source_path)) {
        $result['message'] = __('Source file does not exist.', 'ccm-tools');
        return $result;
    }
    
    // Get file info
    $source_size = filesize($source_path);
    $result['source_size'] = $source_size;
    
    $path_info = pathinfo($source_path);
    $source_ext = strtolower($path_info['extension'] ?? '');
    
    // Check if source is a convertible format
    $allowed_types = array('jpg', 'jpeg', 'png', 'gif');
    if (!in_array($source_ext, $allowed_types)) {
        $result['message'] = sprintf(__('File type .%s is not supported for WebP conversion.', 'ccm-tools'), $source_ext);
        return $result;
    }
    
    // Generate destination path if not provided
    if (empty($dest_path)) {
        $dest_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';
    }
    $result['dest_path'] = $dest_path;
    
    // Determine which extension to use
    if ($extension === 'auto') {
        $extension = ccm_tools_webp_get_best_extension();
    }
    
    if (!$extension) {
        $result['message'] = __('No image processing extension with WebP support is available.', 'ccm-tools');
        return $result;
    }
    
    $result['extension_used'] = $extension;
    
    // Clamp quality
    $quality = max(1, min(100, intval($quality)));
    
    // Perform conversion based on extension
    try {
        switch ($extension) {
            case 'imagick':
                $result = ccm_tools_webp_convert_with_imagick($source_path, $dest_path, $quality, $result);
                break;
                
            case 'gd':
                $result = ccm_tools_webp_convert_with_gd($source_path, $dest_path, $quality, $result);
                break;
                
            case 'vips':
                $result = ccm_tools_webp_convert_with_vips($source_path, $dest_path, $quality, $result);
                break;
                
            default:
                $result['message'] = sprintf(__('Unknown extension: %s', 'ccm-tools'), $extension);
        }
    } catch (Exception $e) {
        $result['message'] = sprintf(__('Conversion error: %s', 'ccm-tools'), $e->getMessage());
    }
    
    // Calculate savings if successful
    if ($result['success'] && file_exists($dest_path)) {
        $dest_size = filesize($dest_path);
        $result['dest_size'] = $dest_size;
        
        if ($source_size > 0) {
            $result['savings_percent'] = round((($source_size - $dest_size) / $source_size) * 100, 1);
        }
    }
    
    return $result;
}

/**
 * Convert image using ImageMagick
 */
function ccm_tools_webp_convert_with_imagick($source_path, $dest_path, $quality, $result) {
    $imagick = new Imagick($source_path);
    
    // Get source format to determine if it's lossless (PNG/GIF)
    $source_format = strtolower($imagick->getImageFormat());
    $is_lossless_source = in_array($source_format, array('png', 'gif'));
    
    // Strip metadata to reduce file size (skip ICC profile handling for speed)
    $imagick->stripImage();
    
    // Set WebP format
    $imagick->setImageFormat('webp');
    
    // Set compression quality
    $imagick->setImageCompressionQuality($quality);
    
    // Determine compression mode based on source type and quality setting
    if ($is_lossless_source) {
        // PNG/GIF: Use lossless to preserve sharp edges and transparency
        $imagick->setOption('webp:lossless', 'true');
        $imagick->setOption('webp:alpha-quality', '100');
    } else if ($quality >= 95) {
        // Very high quality: use lossless
        $imagick->setOption('webp:lossless', 'true');
    } else {
        // Standard/high quality: lossy compression (faster than near-lossless)
        $imagick->setOption('webp:lossless', 'false');
    }
    
    // Use method 4 - good balance of speed and compression (0=fast, 6=slow)
    $imagick->setOption('webp:method', '4');
    
    // Write the file
    if ($imagick->writeImage($dest_path)) {
        $result['success'] = true;
        $result['message'] = __('Successfully converted with ImageMagick.', 'ccm-tools');
    } else {
        $result['message'] = __('ImageMagick failed to write the WebP file.', 'ccm-tools');
    }
    
    $imagick->destroy();
    
    return $result;
}

/**
 * Convert image using GD Library
 */
function ccm_tools_webp_convert_with_gd($source_path, $dest_path, $quality, $result) {
    $path_info = pathinfo($source_path);
    $source_ext = strtolower($path_info['extension'] ?? '');
    
    // Load source image
    $source_image = null;
    
    switch ($source_ext) {
        case 'jpg':
        case 'jpeg':
            $source_image = imagecreatefromjpeg($source_path);
            break;
            
        case 'png':
            $source_image = imagecreatefrompng($source_path);
            // Preserve transparency
            imagepalettetotruecolor($source_image);
            imagealphablending($source_image, true);
            imagesavealpha($source_image, true);
            break;
            
        case 'gif':
            $source_image = imagecreatefromgif($source_path);
            break;
    }
    
    if (!$source_image) {
        $result['message'] = __('GD Library failed to load the source image.', 'ccm-tools');
        return $result;
    }
    
    // Convert to WebP
    if (imagewebp($source_image, $dest_path, $quality)) {
        $result['success'] = true;
        $result['message'] = __('Successfully converted with GD Library.', 'ccm-tools');
    } else {
        $result['message'] = __('GD Library failed to create the WebP file.', 'ccm-tools');
    }
    
    imagedestroy($source_image);
    
    return $result;
}

/**
 * Convert image using libvips
 */
function ccm_tools_webp_convert_with_vips($source_path, $dest_path, $quality, $result) {
    // VIPS conversion (if extension is available)
    if (function_exists('vips_image_new_from_file')) {
        $image = vips_image_new_from_file($source_path);
        
        if ($image) {
            $save_result = vips_image_write_to_file($image, $dest_path, array(
                'Q' => $quality,
                'lossless' => false
            ));
            
            if ($save_result) {
                $result['success'] = true;
                $result['message'] = __('Successfully converted with libvips.', 'ccm-tools');
            } else {
                $result['message'] = __('libvips failed to write the WebP file.', 'ccm-tools');
            }
        } else {
            $result['message'] = __('libvips failed to load the source image.', 'ccm-tools');
        }
    } else {
        $result['message'] = __('libvips extension is not properly loaded.', 'ccm-tools');
    }
    
    return $result;
}

/**
 * Hook into WordPress upload to convert images automatically
 */
function ccm_tools_webp_handle_upload($metadata, $attachment_id) {
    $settings = ccm_tools_webp_get_settings();
    
    // Check if feature is enabled
    if (empty($settings['enabled']) || empty($settings['convert_on_upload'])) {
        return $metadata;
    }
    
    // Get the upload directory
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    
    // Get attachment file path
    $file_path = get_attached_file($attachment_id);
    
    if (!$file_path || !file_exists($file_path)) {
        return $metadata;
    }
    
    // Check if it's an image type we can convert
    $mime_type = get_post_mime_type($attachment_id);
    $allowed_mimes = array('image/jpeg', 'image/png', 'image/gif');
    
    if (!in_array($mime_type, $allowed_mimes)) {
        return $metadata;
    }
    
    $quality = intval($settings['quality']);
    $converted_files = array();
    
    // Convert the main file
    $main_result = ccm_tools_webp_convert_image($file_path, '', $quality);
    if ($main_result['success']) {
        $converted_files['full'] = $main_result;
    }
    
    // Convert all generated sizes
    if (!empty($metadata['sizes'])) {
        $file_dir = dirname($file_path);
        
        foreach ($metadata['sizes'] as $size_name => $size_data) {
            // Skip excluded sizes
            if (in_array($size_name, $settings['exclude_sizes'])) {
                continue;
            }
            
            $size_file_path = $file_dir . '/' . $size_data['file'];
            
            if (file_exists($size_file_path)) {
                $size_result = ccm_tools_webp_convert_image($size_file_path, '', $quality);
                if ($size_result['success']) {
                    $converted_files[$size_name] = $size_result;
                }
            }
        }
    }
    
    // Store conversion info as post meta
    if (!empty($converted_files)) {
        update_post_meta($attachment_id, '_ccm_webp_converted', $converted_files);
    }
    
    return $metadata;
}

/**
 * Filter image URLs on frontend to serve WebP versions
 */
function ccm_tools_webp_filter_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    $settings = ccm_tools_webp_get_settings();
    
    // Check if feature is enabled
    if (empty($settings['enabled']) || empty($settings['serve_webp'])) {
        return $sources;
    }
    
    // Check if browser supports WebP
    if (!ccm_tools_webp_browser_supports_webp()) {
        return $sources;
    }
    
    foreach ($sources as $width => $source) {
        $original_url = $source['url'];
        
        // Try to get or create WebP version
        $webp_url = ccm_tools_webp_get_or_create($original_url);
        
        if ($webp_url && $webp_url !== $original_url) {
            $sources[$width]['url'] = $webp_url;
            $sources[$width]['mime-type'] = 'image/webp';
        }
    }
    
    return $sources;
}

/**
 * Filter main image src to serve WebP
 */
function ccm_tools_webp_filter_image_src($image, $attachment_id, $size) {
    $settings = ccm_tools_webp_get_settings();
    
    // Check if feature is enabled
    if (empty($settings['enabled']) || empty($settings['serve_webp'])) {
        return $image;
    }
    
    // Check if browser supports WebP
    if (!ccm_tools_webp_browser_supports_webp()) {
        return $image;
    }
    
    if (!is_array($image) || empty($image[0])) {
        return $image;
    }
    
    $original_url = $image[0];
    
    // Skip if already WebP
    if (preg_match('/\.webp$/i', $original_url)) {
        return $image;
    }
    
    // Try to get or create WebP version
    $webp_url = ccm_tools_webp_get_or_create($original_url);
    
    if ($webp_url && $webp_url !== $original_url) {
        $image[0] = $webp_url;
    }
    
    return $image;
}

/**
 * Check if browser supports WebP
 * 
 * @return bool
 */
function ccm_tools_webp_browser_supports_webp() {
    // Check Accept header
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false) {
        return true;
    }
    
    return false;
}

/**
 * Convert image to WebP on-demand if it doesn't exist
 * 
 * @param string $source_path Path to original image
 * @param string $webp_path Path where WebP should be created
 * @return bool True if WebP exists or was created successfully
 */
function ccm_tools_webp_convert_on_demand($source_path, $webp_path) {
    // If WebP already exists, nothing to do
    if (file_exists($webp_path)) {
        return true;
    }
    
    // Check if source exists
    if (!file_exists($source_path)) {
        return false;
    }
    
    // Get settings
    $settings = ccm_tools_webp_get_settings();
    
    // Check if on-demand conversion is enabled
    if (empty($settings['convert_on_demand'])) {
        return false;
    }
    
    // Check if we've already failed to convert this file (avoid repeated attempts)
    $failed_key = 'ccm_webp_failed_' . md5($source_path);
    if (get_transient($failed_key)) {
        return false;
    }
    
    // Perform the conversion
    $quality = intval($settings['quality']);
    $extension = $settings['preferred_extension'];
    
    $result = ccm_tools_webp_convert_image($source_path, $webp_path, $quality, $extension);
    
    if ($result['success']) {
        // Try to find attachment ID and update meta
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $source_path);
        
        global $wpdb;
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
            $relative_path
        ));
        
        if ($attachment_id) {
            // Update conversion meta
            $converted = get_post_meta($attachment_id, '_ccm_webp_converted', true);
            if (!is_array($converted)) {
                $converted = array();
            }
            $converted['on_demand'] = array(
                'success' => true,
                'source_path' => $source_path,
                'dest_path' => $webp_path,
                'source_size' => $result['source_size'],
                'dest_size' => $result['dest_size'],
                'converted_at' => current_time('mysql')
            );
            update_post_meta($attachment_id, '_ccm_webp_converted', $converted);
        }
        
        return true;
    } else {
        // Mark as failed to avoid repeated attempts (cache for 1 hour)
        set_transient($failed_key, true, HOUR_IN_SECONDS);
        return false;
    }
}

/**
 * Queue an image for background WebP conversion
 * This adds the image to a queue that will be processed asynchronously
 * 
 * @param string $original_url The original image URL
 * @return void
 */
function ccm_tools_webp_queue_for_conversion($original_url) {
    $settings = ccm_tools_webp_get_settings();
    
    // Check if on-demand conversion is enabled
    if (empty($settings['enabled']) || empty($settings['convert_on_demand'])) {
        return;
    }
    
    // Skip if already WebP
    if (preg_match('/\.webp$/i', $original_url)) {
        return;
    }
    
    // Only process JPG, PNG, GIF
    if (!preg_match('/\.(jpe?g|png|gif)$/i', $original_url)) {
        return;
    }
    
    $upload_dir = wp_upload_dir();
    
    // Check if this is a local upload
    if (strpos($original_url, $upload_dir['baseurl']) === false) {
        return;
    }
    
    // Generate paths
    $original_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $original_url);
    $webp_path = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $original_path);
    
    // Skip if WebP already exists
    if (file_exists($webp_path)) {
        return;
    }
    
    // Check if already failed
    $failed_key = 'ccm_webp_failed_' . md5($original_path);
    if (get_transient($failed_key)) {
        return;
    }
    
    // Add to conversion queue
    $queue = get_transient('ccm_webp_conversion_queue') ?: array();
    $queue_key = md5($original_url);
    
    if (!isset($queue[$queue_key])) {
        $queue[$queue_key] = array(
            'url' => $original_url,
            'source_path' => $original_path,
            'webp_path' => $webp_path,
            'queued_at' => time()
        );
        set_transient('ccm_webp_conversion_queue', $queue, 3600); // Queue expires after 1 hour
    }
}

/**
 * Get or create WebP version of an image URL
 * Now queues for background conversion instead of blocking
 * Returns the WebP URL if it exists, otherwise returns false and queues conversion
 * 
 * @param string $original_url The original image URL
 * @param bool $queue_if_missing Whether to queue for conversion if WebP doesn't exist
 * @return string|false WebP URL or false if not available
 */
function ccm_tools_webp_get_or_create($original_url, $queue_if_missing = true) {
    // Skip if already WebP
    if (preg_match('/\.webp$/i', $original_url)) {
        return $original_url;
    }
    
    // Only process JPG, PNG, GIF
    if (!preg_match('/\.(jpe?g|png|gif)$/i', $original_url)) {
        return false;
    }
    
    $upload_dir = wp_upload_dir();
    
    // Check if this is a local upload
    if (strpos($original_url, $upload_dir['baseurl']) === false) {
        return false;
    }
    
    // Generate paths
    $webp_url = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $original_url);
    $webp_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $webp_url);
    
    // Check if WebP exists
    if (file_exists($webp_path)) {
        return $webp_url;
    }
    
    // Queue for background conversion (non-blocking)
    if ($queue_if_missing) {
        ccm_tools_webp_queue_for_conversion($original_url);
    }
    
    return false;
}

/**
 * Convert img tags to picture tags with WebP sources
 * 
 * @param string $content The post content
 * @return string Modified content with picture tags
 */
function ccm_tools_webp_convert_to_picture_tags($content) {
    $settings = ccm_tools_webp_get_settings();
    
    // Check if feature is enabled
    if (empty($settings['enabled']) || empty($settings['use_picture_tags'])) {
        return $content;
    }
    
    // Don't process if content is empty
    if (empty($content)) {
        return $content;
    }
    
    // Get upload directory info
    $upload_dir = wp_upload_dir();
    
    // TWO-PASS APPROACH to handle nested picture tags correctly
    // Pass 1: Mark all img tags that are already inside <picture> elements
    $content = preg_replace_callback(
        '/<picture[^>]*>(.*?)<\/picture>/is',
        function($matches) {
            $picture_content = $matches[1];
            // Add data-inside-picture to any img tags inside this picture element
            $marked = preg_replace(
                '/<img\s+/i',
                '<img data-inside-picture="true" ',
                $picture_content
            );
            return '<picture>' . $marked . '</picture>';
        },
        $content
    );
    
    // Pattern to match img tags - including WebP images (for fallback to original format)
    $pattern = '/<img\s+([^>]*?)src=["\']([^"\']+\.(jpe?g|png|gif|webp))["\']([^>]*?)>/i';
    
    // Pass 2: Convert img tags that are NOT marked
    $content = preg_replace_callback($pattern, function($matches) use ($upload_dir) {
        $full_match = $matches[0];
        $before_src = $matches[1];
        $img_url = $matches[2];
        $extension = strtolower($matches[3]);
        $after_src = $matches[4];
        
        // Skip if already has data-no-picture marker (was already processed)
        if (strpos($before_src, 'data-no-picture') !== false || strpos($after_src, 'data-no-picture') !== false) {
            return $full_match;
        }
        
        // Skip if marked as inside a picture element (from Pass 1)
        if (strpos($before_src, 'data-inside-picture') !== false || strpos($after_src, 'data-inside-picture') !== false) {
            return $full_match;
        }
        
        // Check if this is a local upload
        if (strpos($img_url, $upload_dir['baseurl']) === false) {
            // External image, skip conversion
            return $full_match;
        }
        
        // Handle both scenarios: source is WebP or source is JPG/PNG/GIF
        $is_source_webp = ($extension === 'webp');
        $webp_url = '';
        $original_url = '';
        $original_extension = '';
        
        if ($is_source_webp) {
            // Source is WebP - try to find original JPG/PNG fallback
            $webp_url = $img_url;
            
            // Try to find the original file (check jpg, jpeg, png, gif)
            $base_url = preg_replace('/\.webp$/i', '', $img_url);
            $base_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $base_url);
            
            foreach (array('jpg', 'jpeg', 'png', 'gif') as $try_ext) {
                if (file_exists($base_path . '.' . $try_ext)) {
                    $original_url = $base_url . '.' . $try_ext;
                    $original_extension = $try_ext;
                    break;
                }
            }
            
            // If no original found, can't create picture tag with fallback
            if (empty($original_url)) {
                return $full_match;
            }
        } else {
            // Source is JPG/PNG/GIF - try to find or create WebP version
            $original_url = $img_url;
            $original_extension = $extension;
            
            // Use on-demand conversion if enabled
            $webp_url = ccm_tools_webp_get_or_create($img_url);
            
            if (!$webp_url || $webp_url === $img_url) {
                // No WebP version exists and couldn't create one, return original
                return $full_match;
            }
        }
        
        // Extract srcset if present and convert URLs
        $srcset_webp = '';
        $srcset_original = '';
        if (preg_match('/srcset=["\']([^"\']+)["\']/', $before_src . $after_src, $srcset_match)) {
            $srcset_value = $srcset_match[1];
            
            if ($is_source_webp) {
                // srcset is WebP, convert to original format
                $srcset_webp = $srcset_value;
                $srcset_original = preg_replace('/\.webp(\s)/i', '.' . $original_extension . '$1', $srcset_value);
                $srcset_original = preg_replace('/\.webp(,)/i', '.' . $original_extension . '$1', $srcset_original);
                $srcset_original = preg_replace('/\.webp$/i', '.' . $original_extension, $srcset_original);
            } else {
                // srcset is original format, convert to WebP
                $srcset_original = $srcset_value;
                $srcset_webp = preg_replace('/\.(jpe?g|png|gif)(\s)/i', '.webp$2', $srcset_value);
                $srcset_webp = preg_replace('/\.(jpe?g|png|gif)(,)/i', '.webp$2', $srcset_webp);
                $srcset_webp = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $srcset_webp);
            }
        }
        
        // Build the picture element
        $picture = '<picture>';
        
        // WebP source (first for browsers that support it)
        if (!empty($srcset_webp)) {
            $picture .= '<source type="image/webp" srcset="' . esc_attr($srcset_webp) . '">';
        } else {
            $picture .= '<source type="image/webp" srcset="' . esc_url($webp_url) . '">';
        }
        
        // Original format source (fallback for browsers that don't support WebP)
        $mime_type = 'image/' . ($original_extension === 'jpg' ? 'jpeg' : strtolower($original_extension));
        if (!empty($srcset_original)) {
            $picture .= '<source type="' . esc_attr($mime_type) . '" srcset="' . esc_attr($srcset_original) . '">';
        } else {
            $picture .= '<source type="' . esc_attr($mime_type) . '" srcset="' . esc_url($original_url) . '">';
        }
        
        // Use original format img tag as fallback (most compatible)
        // Update the src in the img tag to use the original format for maximum compatibility
        $fallback_img = '<img ' . $before_src . 'src="' . esc_url($original_url) . '"' . $after_src . ' data-no-picture="true">';
        
        $picture .= $fallback_img;
        $picture .= '</picture>';
        
        return $picture;
    }, $content);
    
    // Pass 3: Remove the temporary data-inside-picture markers
    $content = str_replace(' data-inside-picture="true"', '', $content);
    
    return $content;
}

/**
 * Filter to convert img tags in post content to picture tags
 * 
 * @param string $content The post content
 * @return string Modified content
 */
function ccm_tools_webp_filter_content($content) {
    // Don't process in admin, feeds, or REST API
    if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $content;
    }
    
    return ccm_tools_webp_convert_to_picture_tags($content);
}

/**
 * Filter to convert img tags in widgets to picture tags
 * 
 * @param string $content The widget content
 * @return string Modified content
 */
function ccm_tools_webp_filter_widget($content) {
    return ccm_tools_webp_convert_to_picture_tags($content);
}

/**
 * Filter WooCommerce product thumbnail HTML to use picture tags
 * 
 * @param string $html The product thumbnail HTML
 * @param int $post_id The post ID
 * @return string Modified HTML
 */
function ccm_tools_webp_filter_wc_product_image($html, $post_id = 0) {
    return ccm_tools_webp_convert_to_picture_tags($html);
}

/**
 * Filter WooCommerce single product image HTML
 * 
 * @param string $html The image HTML
 * @param int $attachment_id The attachment ID
 * @return string Modified HTML
 */
function ccm_tools_webp_filter_wc_single_product_image($html, $attachment_id = 0) {
    return ccm_tools_webp_convert_to_picture_tags($html);
}

/**
 * Add WebP as allowed upload type
 */
function ccm_tools_webp_allowed_mimes($mimes) {
    $mimes['webp'] = 'image/webp';
    return $mimes;
}

/**
 * Get conversion statistics
 * 
 * @return array Statistics
 */
function ccm_tools_webp_get_statistics() {
    global $wpdb;
    
    $stats = array(
        'total_images' => 0,
        'converted_images' => 0,
        'total_original_size' => 0,
        'total_webp_size' => 0,
        'total_savings' => 0,
        'pending_conversion' => 0
    );
    
    // Count total images
    $stats['total_images'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} 
         WHERE post_type = 'attachment' 
         AND post_mime_type IN ('image/jpeg', 'image/png', 'image/gif')"
    );
    
    // Count converted images
    $stats['converted_images'] = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} 
         WHERE meta_key = '_ccm_webp_converted'"
    );
    
    // Calculate pending
    $stats['pending_conversion'] = max(0, $stats['total_images'] - $stats['converted_images']);
    
    // Get size statistics from converted images
    $converted_meta = $wpdb->get_col(
        "SELECT meta_value FROM {$wpdb->postmeta} 
         WHERE meta_key = '_ccm_webp_converted'"
    );
    
    foreach ($converted_meta as $meta_value) {
        $data = maybe_unserialize($meta_value);
        if (is_array($data)) {
            foreach ($data as $size_data) {
                if (isset($size_data['source_size'])) {
                    $stats['total_original_size'] += intval($size_data['source_size']);
                }
                if (isset($size_data['dest_size'])) {
                    $stats['total_webp_size'] += intval($size_data['dest_size']);
                }
            }
        }
    }
    
    // Calculate total savings
    if ($stats['total_original_size'] > 0) {
        $stats['total_savings'] = round(
            (($stats['total_original_size'] - $stats['total_webp_size']) / $stats['total_original_size']) * 100,
            1
        );
    }
    
    return $stats;
}

/**
 * Initialize WebP converter hooks when enabled
 */
function ccm_tools_webp_init() {
    $settings = ccm_tools_webp_get_settings();
    
    // Always allow WebP uploads
    add_filter('upload_mimes', 'ccm_tools_webp_allowed_mimes');
    
    if (empty($settings['enabled'])) {
        return;
    }
    
    // Hook into upload process
    if (!empty($settings['convert_on_upload'])) {
        add_filter('wp_generate_attachment_metadata', 'ccm_tools_webp_handle_upload', 10, 2);
    }
    
    // Hook into frontend image display
    if (!empty($settings['serve_webp'])) {
        add_filter('wp_calculate_image_srcset', 'ccm_tools_webp_filter_image_srcset', 10, 5);
        add_filter('wp_get_attachment_image_src', 'ccm_tools_webp_filter_image_src', 10, 3);
    }
    
    // Hook into content for picture tag conversion
    if (!empty($settings['use_picture_tags'])) {
        add_filter('the_content', 'ccm_tools_webp_filter_content', 999);
        add_filter('widget_text', 'ccm_tools_webp_filter_widget', 999);
        add_filter('widget_block_content', 'ccm_tools_webp_filter_widget', 999);
        
        // WooCommerce specific hooks for product images
        add_filter('woocommerce_product_get_image', 'ccm_tools_webp_filter_wc_product_image', 999, 2);
        add_filter('woocommerce_single_product_image_thumbnail_html', 'ccm_tools_webp_filter_wc_single_product_image', 999, 2);
        
        // NOTE: Removed post_thumbnail_html and wp_get_attachment_image hooks
        // These run BEFORE themes add their own <picture> wrappers, causing double-wrapping
        // Images from these hooks will still get WebP served via srcset filters instead
    }
    
    // Add background queue processor for on-demand conversion
    if (!empty($settings['convert_on_demand']) && !is_admin()) {
        add_action('wp_footer', 'ccm_tools_webp_background_queue_script', 999);
    }
}
add_action('init', 'ccm_tools_webp_init');

/**
 * Output JavaScript for background WebP queue processing
 * This runs after page load to convert queued images without blocking
 */
function ccm_tools_webp_background_queue_script() {
    // Check if there's anything in the queue
    $queue = get_transient('ccm_webp_conversion_queue');
    if (empty($queue)) {
        return;
    }
    
    ?>
    <script>
    (function() {
        // Process WebP conversion queue in background
        // Runs after page is fully loaded to avoid blocking
        if (document.readyState === 'complete') {
            processWebPQueue();
        } else {
            window.addEventListener('load', processWebPQueue);
        }
        
        function processWebPQueue() {
            // Small delay to ensure page is interactive first
            setTimeout(function() {
                doProcessBatch();
            }, 2000);
        }
        
        function doProcessBatch() {
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=ccm_tools_process_webp_queue'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data.remaining > 0) {
                    // More images to process, continue after delay
                    setTimeout(doProcessBatch, 1000);
                }
            })
            .catch(err => console.debug('WebP background conversion:', err));
        }
    })();
    </script>
    <?php
}

/**
 * Render the WebP Converter admin page
 */
function ccm_tools_render_webp_page() {
    // Check if WebP conversion is available
    $available = ccm_tools_webp_is_available();
    $extensions = ccm_tools_webp_get_available_extensions();
    $settings = ccm_tools_webp_get_settings();
    $stats = ccm_tools_webp_get_statistics();
    $best_extension = ccm_tools_webp_get_best_extension();
    
    ?>
    <div class="wrap ccm-tools">
        <div class="ccm-header">
            <div class="ccm-header-logo">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools')); ?>">
                    <img src="<?php echo esc_url(CCM_HELPER_ROOT_URL); ?>img/logo.svg" alt="CCM Tools">
                </a>
            </div>
            <nav class="ccm-header-menu">
                <div class="ccm-tabs">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools')); ?>" class="ccm-tab"><?php _e('System Info', 'ccm-tools'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-database')); ?>" class="ccm-tab"><?php _e('Database', 'ccm-tools'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-htaccess')); ?>" class="ccm-tab"><?php _e('.htaccess', 'ccm-tools'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-woocommerce')); ?>" class="ccm-tab"><?php _e('WooCommerce', 'ccm-tools'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-error-log')); ?>" class="ccm-tab"><?php _e('Error Log', 'ccm-tools'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ccm-tools-webp')); ?>" class="ccm-tab active"><?php _e('WebP', 'ccm-tools'); ?></a>
                </div>
            </nav>
            <div class="ccm-header-title">
                <h1><?php _e('WebP Converter', 'ccm-tools'); ?></h1>
            </div>
        </div>
        
        <div class="ccm-content">
            <!-- Available Extensions -->
            <div class="ccm-card">
                <h2><?php _e('Image Processing Extensions', 'ccm-tools'); ?></h2>
                
                <?php if (empty($extensions)): ?>
                    <div class="ccm-alert ccm-alert-error">
                        <span class="ccm-icon">✗</span>
                        <div>
                            <strong><?php _e('No Compatible Extensions Found', 'ccm-tools'); ?></strong>
                            <p><?php _e('WebP conversion requires GD, ImageMagick, or libvips PHP extension with WebP support. Please contact your hosting provider to enable one of these extensions.', 'ccm-tools'); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="ccm-extensions-grid">
                        <?php foreach ($extensions as $name => $ext): ?>
                            <div class="ccm-extension-item <?php echo $ext['webp_support'] ? 'ccm-success' : 'ccm-warning'; ?>">
                                <div class="ccm-extension-header">
                                    <span class="ccm-icon"><?php echo $ext['webp_support'] ? '✓' : '⚠'; ?></span>
                                    <div class="ccm-extension-name">
                                        <strong><?php echo esc_html($ext['name']); ?></strong>
                                        <?php if ($name === $best_extension): ?>
                                            <span class="ccm-badge ccm-badge-primary"><?php _e('Recommended', 'ccm-tools'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="ccm-extension-details">
                                    <small><?php _e('Version:', 'ccm-tools'); ?> <?php echo esc_html($ext['version']); ?></small>
                                    <div class="ccm-extension-support">
                                        <span class="<?php echo $ext['webp_support'] ? 'ccm-success' : 'ccm-error'; ?>" title="WebP">WebP <?php echo $ext['webp_support'] ? '✓' : '✗'; ?></span>
                                        <span class="<?php echo $ext['jpeg_support'] ? 'ccm-success' : 'ccm-error'; ?>" title="JPEG">JPEG <?php echo $ext['jpeg_support'] ? '✓' : '✗'; ?></span>
                                        <span class="<?php echo $ext['png_support'] ? 'ccm-success' : 'ccm-error'; ?>" title="PNG">PNG <?php echo $ext['png_support'] ? '✓' : '✗'; ?></span>
                                        <span class="<?php echo $ext['gif_support'] ? 'ccm-success' : 'ccm-error'; ?>" title="GIF">GIF <?php echo $ext['gif_support'] ? '✓' : '✗'; ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($available): ?>
            
            <!-- Conversion Statistics -->
            <div class="ccm-card" id="webp-stats-card">
                <h2><?php _e('Conversion Statistics', 'ccm-tools'); ?></h2>
                <div class="ccm-stats-grid">
                    <div class="ccm-stat-box">
                        <span class="ccm-stat-value" id="stat-total-images"><?php echo esc_html($stats['total_images']); ?></span>
                        <span class="ccm-stat-label"><?php _e('Total Images', 'ccm-tools'); ?></span>
                    </div>
                    <div class="ccm-stat-box">
                        <span class="ccm-stat-value ccm-success" id="stat-converted-images"><?php echo esc_html($stats['converted_images']); ?></span>
                        <span class="ccm-stat-label"><?php _e('Converted to WebP', 'ccm-tools'); ?></span>
                    </div>
                    <div class="ccm-stat-box">
                        <span class="ccm-stat-value <?php echo $stats['pending_conversion'] > 0 ? 'ccm-warning' : ''; ?>" id="stat-pending-images"><?php echo esc_html($stats['pending_conversion']); ?></span>
                        <span class="ccm-stat-label"><?php _e('Pending Conversion', 'ccm-tools'); ?></span>
                    </div>
                    <div class="ccm-stat-box">
                        <span class="ccm-stat-value ccm-info" id="stat-average-savings"><?php echo esc_html($stats['total_savings']); ?>%</span>
                        <span class="ccm-stat-label"><?php _e('Average Savings', 'ccm-tools'); ?></span>
                    </div>
                </div>
                
                <div class="ccm-size-comparison" id="stat-size-comparison" <?php echo $stats['total_original_size'] > 0 ? '' : 'style="display:none;"'; ?>>
                    <p>
                        <strong><?php _e('Original Size:', 'ccm-tools'); ?></strong> 
                        <span id="stat-original-size"><?php echo esc_html(size_format($stats['total_original_size'])); ?></span>
                        &rarr;
                        <strong><?php _e('WebP Size:', 'ccm-tools'); ?></strong> 
                        <span id="stat-webp-size"><?php echo esc_html(size_format($stats['total_webp_size'])); ?></span>
                        <span class="ccm-success">
                            (<span id="stat-saved-size"><?php echo sprintf(__('Saved %s', 'ccm-tools'), size_format($stats['total_original_size'] - $stats['total_webp_size'])); ?></span>)
                        </span>
                    </p>
                </div>
            </div>
            
            <!-- Settings -->
            <div class="ccm-card">
                <h2><?php _e('WebP Converter Settings', 'ccm-tools'); ?></h2>
                
                <form id="webp-settings-form">
                    <table class="ccm-table ccm-form-table">
                        <tr>
                            <th><?php _e('Enable WebP Conversion', 'ccm-tools'); ?></th>
                            <td>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="enabled" id="webp-enabled" value="1" <?php checked($settings['enabled'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                                <p class="ccm-note"><?php _e('Master switch to enable/disable all WebP conversion features.', 'ccm-tools'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Compression Quality', 'ccm-tools'); ?></th>
                            <td>
                                <div class="ccm-range-control">
                                    <input type="range" name="quality" id="webp-quality" min="1" max="100" value="<?php echo esc_attr($settings['quality']); ?>">
                                    <span class="ccm-range-value" id="webp-quality-value"><?php echo esc_html($settings['quality']); ?></span>
                                </div>
                                <p class="ccm-note">
                                    <?php _e('1 = smallest file, lowest quality. 100 = largest file, best quality. Recommended: 75-85.', 'ccm-tools'); ?>
                                </p>
                                <div class="ccm-quality-presets">
                                    <button type="button" class="ccm-button ccm-button-small ccm-quality-preset" data-quality="60"><?php _e('Low (60)', 'ccm-tools'); ?></button>
                                    <button type="button" class="ccm-button ccm-button-small ccm-quality-preset" data-quality="75"><?php _e('Medium (75)', 'ccm-tools'); ?></button>
                                    <button type="button" class="ccm-button ccm-button-small ccm-quality-preset" data-quality="82"><?php _e('Balanced (82)', 'ccm-tools'); ?></button>
                                    <button type="button" class="ccm-button ccm-button-small ccm-quality-preset" data-quality="90"><?php _e('High (90)', 'ccm-tools'); ?></button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Convert on Upload', 'ccm-tools'); ?></th>
                            <td>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="convert_on_upload" id="webp-convert-on-upload" value="1" <?php checked($settings['convert_on_upload'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                                <p class="ccm-note"><?php _e('Automatically convert images to WebP when they are uploaded.', 'ccm-tools'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Serve WebP to Browsers', 'ccm-tools'); ?></th>
                            <td>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="serve_webp" id="webp-serve" value="1" <?php checked($settings['serve_webp'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                                <p class="ccm-note"><?php _e('Automatically serve WebP images to browsers that support them. Original images are served to older browsers.', 'ccm-tools'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Convert On-Demand', 'ccm-tools'); ?></th>
                            <td>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="convert_on_demand" id="webp-convert-on-demand" value="1" <?php checked($settings['convert_on_demand'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                                <p class="ccm-note"><?php _e('Automatically convert images to WebP when they are displayed on a page (if WebP doesn\'t exist yet). This enables lazy/on-the-fly conversion without needing bulk conversion.', 'ccm-tools'); ?></p>
                                <div class="ccm-alert ccm-alert-info ccm-alert-small">
                                    <span class="ccm-icon">ℹ</span>
                                    <small><?php _e('First page load may be slightly slower while images are converted, but subsequent loads will be fast.', 'ccm-tools'); ?></small>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Use &lt;picture&gt; Tags', 'ccm-tools'); ?></th>
                            <td>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="use_picture_tags" id="webp-picture-tags" value="1" <?php checked($settings['use_picture_tags'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                                <p class="ccm-note"><?php _e('Convert &lt;img&gt; tags to &lt;picture&gt; tags with WebP sources. This provides automatic fallback for browsers that don\'t support WebP.', 'ccm-tools'); ?></p>
                                <div class="ccm-code-example">
                                    <small><?php _e('Example output:', 'ccm-tools'); ?></small>
                                    <pre>&lt;picture&gt;
  &lt;source type="image/webp" srcset="image.webp"&gt;
  &lt;img src="image.jpg" alt="..."&gt;
&lt;/picture&gt;</pre>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Keep Original Files', 'ccm-tools'); ?></th>
                            <td>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="keep_originals" id="webp-keep-originals" value="1" <?php checked($settings['keep_originals'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                                <p class="ccm-note"><?php _e('Keep original JPG/PNG files alongside WebP versions. Recommended for compatibility.', 'ccm-tools'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Preferred Extension', 'ccm-tools'); ?></th>
                            <td>
                                <select name="preferred_extension" id="webp-preferred-extension">
                                    <option value="auto" <?php selected($settings['preferred_extension'], 'auto'); ?>><?php _e('Auto (Best Available)', 'ccm-tools'); ?></option>
                                    <?php foreach ($extensions as $name => $ext): ?>
                                        <?php if ($ext['webp_support']): ?>
                                            <option value="<?php echo esc_attr($name); ?>" <?php selected($settings['preferred_extension'], $name); ?>>
                                                <?php echo esc_html($ext['name']); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <p class="ccm-note"><?php _e('Choose which image processing library to use for conversion.', 'ccm-tools'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <div class="ccm-form-actions">
                        <button type="submit" id="save-webp-settings" class="ccm-button ccm-button-primary">
                            <?php _e('Save Settings', 'ccm-tools'); ?>
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Bulk Conversion -->
            <div class="ccm-card">
                <h2><?php _e('Bulk Convert Existing Images', 'ccm-tools'); ?></h2>
                <p><?php _e('Convert all existing images in your media library to WebP format.', 'ccm-tools'); ?></p>
                
                <div class="ccm-alert ccm-alert-info">
                    <span class="ccm-icon">ℹ</span>
                    <div>
                        <strong><?php _e('Before You Start', 'ccm-tools'); ?></strong>
                        <ul>
                            <li><?php _e('This process may take a long time depending on the number of images.', 'ccm-tools'); ?></li>
                            <li><?php _e('Make sure you have a backup of your uploads folder.', 'ccm-tools'); ?></li>
                            <li><?php _e('Keep this browser tab open during conversion.', 'ccm-tools'); ?></li>
                        </ul>
                    </div>
                </div>
                
                <div class="ccm-bulk-actions" style="display: flex; gap: var(--ccm-space-sm); flex-wrap: wrap;">
                    <button type="button" id="start-bulk-conversion" class="ccm-button ccm-button-primary" <?php echo $stats['pending_conversion'] === 0 ? 'disabled' : ''; ?>>
                        <?php echo sprintf(__('Convert %d Images', 'ccm-tools'), $stats['pending_conversion']); ?>
                    </button>
                    <button type="button" id="regenerate-all-webp" class="ccm-button" <?php echo $stats['converted_images'] === 0 ? 'disabled' : ''; ?>>
                        <?php echo sprintf(__('Regenerate %d WebP Images', 'ccm-tools'), $stats['converted_images']); ?>
                    </button>
                    <button type="button" id="stop-bulk-conversion" class="ccm-button ccm-button-danger" style="display: none;">
                        <?php _e('Stop Conversion', 'ccm-tools'); ?>
                    </button>
                </div>
                
                <p class="ccm-text-muted" style="margin-top: var(--ccm-space-sm); font-size: var(--ccm-text-sm);">
                    <?php _e('Use "Regenerate" to re-convert all images with new quality settings.', 'ccm-tools'); ?>
                </p>
                
                <div id="bulk-conversion-progress" style="display: none;">
                    <div class="ccm-progress-info">
                        <p><?php _e('Converting:', 'ccm-tools'); ?> <span id="bulk-current">0</span>/<span id="bulk-total">0</span></p>
                        <div class="ccm-progress-bar">
                            <div class="ccm-progress-fill" id="bulk-progress-bar" style="width: 0%"></div>
                        </div>
                    </div>
                    <div id="bulk-conversion-log" class="ccm-log-box"></div>
                </div>
            </div>
            
            <!-- Test Conversion -->
            <div class="ccm-card">
                <h2><?php _e('Test Conversion', 'ccm-tools'); ?></h2>
                <p><?php _e('Test WebP conversion with a single image to verify everything is working correctly.', 'ccm-tools'); ?></p>
                
                <div class="ccm-test-area">
                    <div class="ccm-file-upload">
                        <input type="file" id="test-image-upload" accept="image/jpeg,image/png,image/gif" style="display: none;">
                        <button type="button" id="select-test-image" class="ccm-button">
                            <?php _e('Select Test Image', 'ccm-tools'); ?>
                        </button>
                        <span id="test-image-name" class="ccm-file-name"></span>
                    </div>
                    
                    <button type="button" id="run-test-conversion" class="ccm-button ccm-button-primary" disabled>
                        <?php _e('Test Conversion', 'ccm-tools'); ?>
                    </button>
                </div>
                
                <div id="test-conversion-result" style="display: none;">
                    <div id="test-result-content"></div>
                </div>
            </div>
            
            <?php endif; // if ($available) ?>
            
        </div>
    </div>
    <?php
}
