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
 * Canonical defaults for WebP converter settings. Shared by the getter and
 * the save-time sanitiser below so there is exactly one place that defines
 * what a setting is and what its default is.
 *
 * @return array Defaults, keyed by setting name
 */
function ccm_tools_webp_get_default_settings() {
    return array(
        'enabled' => false,
        'quality' => 85, // Default to 85 for near-lossless quality
        'convert_on_upload' => true,
        'serve_webp' => true,
        'convert_on_demand' => true,
        'convert_bg_images' => false,
        'keep_originals' => true,
        'convert_existing' => false,
        'exclude_sizes' => array(),
        'preferred_extension' => 'auto'
    );
}

/**
 * Get WebP converter settings
 *
 * @return array Settings array
 */
function ccm_tools_webp_get_settings() {
    $defaults = ccm_tools_webp_get_default_settings();

    $settings = get_option('ccm_tools_webp_settings', array());
    return wp_parse_args($settings, $defaults);
}

/**
 * Save WebP converter settings
 *
 * Settings are whitelist-sanitised against the defaults array before
 * saving: unknown keys are dropped, booleans are cast, and quality is
 * clamped to 1-100, so a malformed payload can't smuggle arbitrary data
 * into the ccm_tools_webp_settings option.
 *
 * @param array $settings Settings to save
 * @return bool Success
 */
function ccm_tools_webp_save_settings($settings) {
    $settings = ccm_tools_webp_sanitize_settings($settings);
    return update_option('ccm_tools_webp_settings', $settings);
}

/**
 * Whitelist-sanitise WebP settings against the canonical defaults: only
 * known keys survive, booleans are cast, quality is clamped to 1-100, and
 * preferred_extension is restricted to a known value.
 *
 * @param array $settings Raw settings (e.g. from an AJAX request or import)
 * @return array Sanitised settings containing only known keys
 */
function ccm_tools_webp_sanitize_settings($settings) {
    $defaults = ccm_tools_webp_get_default_settings();

    if (!is_array($settings)) {
        $settings = array();
    }

    $clean = array();

    foreach ($defaults as $key => $default_value) {
        if (!array_key_exists($key, $settings)) {
            $clean[$key] = $default_value;
            continue;
        }

        $value = $settings[$key];

        if (is_bool($default_value)) {
            $clean[$key] = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : false;
        } elseif ($key === 'quality') {
            $clean[$key] = max(1, min(100, intval($value)));
        } elseif ($key === 'exclude_sizes') {
            $clean[$key] = is_array($value) ? array_map('sanitize_text_field', $value) : array();
        } elseif ($key === 'preferred_extension') {
            $allowed = array('auto', 'gd', 'imagick');
            $clean[$key] = in_array($value, $allowed, true) ? $value : 'auto';
        } else {
            $clean[$key] = is_string($value) ? sanitize_text_field($value) : $value;
        }
    }

    return $clean;
}

/**
 * Convert an image URL to a filesystem path inside the uploads directory,
 * with containment enforced via realpath().
 *
 * Security: prevents path traversal. Without this, a URL such as
 * /wp-content/uploads/../../../../etc/passwd.jpg would resolve (via naive
 * str_replace/regex path building) to a path outside the uploads tree,
 * giving an anonymous visitor a file read (source) or file write
 * (destination) anywhere the webserver user can reach — including other
 * customer accounts on shared hosting. EVERY URL-to-path conversion in this
 * file must go through this function instead of building paths by hand.
 *
 * @param string $url        The image URL (absolute or a /wp-content/uploads/ relative path)
 * @param array  $upload_dir wp_upload_dir() result
 * @param bool   $must_exist Whether the resolved path is expected to already exist on
 *                            disk (e.g. a source image being read). When true, realpath()
 *                            must succeed. When false (e.g. a .webp destination that is
 *                            about to be created), the parent directory is resolved and
 *                            validated instead, and the leaf filename is rebuilt on top of it.
 * @return string|false Absolute, contained filesystem path, or false if unsafe/invalid.
 */
function ccm_tools_webp_url_to_safe_path($url, $upload_dir, $must_exist = false) {
    $url = trim($url);
    if ($url === '') {
        return false;
    }

    // Only allow the image types this file actually handles.
    if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $url)) {
        return false;
    }

    // Build the raw candidate path the same way the original code did.
    $raw_path = '';
    if (strpos($url, $upload_dir['baseurl']) !== false) {
        $raw_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $url);
    } elseif (strpos($url, '/wp-content/uploads/') !== false) {
        if (preg_match('#/wp-content/uploads/(.+)$#', $url, $m)) {
            $raw_path = $upload_dir['basedir'] . '/' . $m[1];
        }
    }

    if ($raw_path === '') {
        return false;
    }

    // Strip any query string / fragment that hitched a ride on the URL.
    $raw_path = preg_replace('/[?#].*$/', '', $raw_path);

    // Resolve the uploads basedir itself first — if THIS fails there is no
    // safe boundary to check against, so refuse everything.
    $real_basedir = realpath($upload_dir['basedir']);
    if ($real_basedir === false) {
        return false;
    }
    $real_basedir_prefix = rtrim($real_basedir, '/\\') . DIRECTORY_SEPARATOR;

    if ($must_exist) {
        // realpath() resolves any ../ traversal AND requires the target to
        // exist, so a traversal attempt that escapes basedir and a path that
        // simply doesn't exist both come back false here.
        $real_path = realpath($raw_path);
        if ($real_path === false) {
            return false;
        }
        if (strpos($real_path . DIRECTORY_SEPARATOR, $real_basedir_prefix) !== 0) {
            return false;
        }
        return $real_path;
    }

    // The path may not exist yet (e.g. a .webp destination we're about to
    // write). realpath() can't validate a nonexistent leaf, so resolve and
    // validate the parent directory instead, then rebuild the leaf on top.
    $dir = dirname($raw_path);
    $basename = basename($raw_path);

    if ($basename === '' || $basename === '.' || $basename === '..' || strpos($basename, '/') !== false || strpos($basename, '\\') !== false) {
        return false;
    }

    $real_dir = realpath($dir);
    if ($real_dir === false) {
        return false;
    }
    if (strpos($real_dir . DIRECTORY_SEPARATOR, $real_basedir_prefix) !== 0) {
        return false;
    }

    return $real_dir . DIRECTORY_SEPARATOR . $basename;
}

/**
 * Convert an image to WebP format
 * 
 * @param string $source_path Path to source image
 * @param string $dest_path Path to destination WebP file (optional)
 * @param int $quality Compression quality (1-100)
 * @param string $extension Which extension to use (auto, gd, imagick)
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
 * Get the PHP memory_limit in bytes.
 *
 * @return int Bytes, or 0 if the limit is unlimited/unreadable (nothing to compare against)
 */
function ccm_tools_webp_get_memory_limit_bytes() {
    $limit = ini_get('memory_limit');
    if ($limit === false || trim((string) $limit) === '-1') {
        return 0;
    }
    return (int) wp_convert_hr_to_bytes($limit);
}

/**
 * Image-bomb guard: read dimensions via getimagesize() (cheap — only the
 * header, not the pixel data) and refuse to decode anything whose pixel
 * count exceeds a sane, filterable cap, or whose estimated decoded memory
 * need won't fit in what PHP has available. Call this BEFORE
 * imagecreatefromjpeg/png/gif() or `new Imagick()` — those decode the
 * entire image into memory regardless of the final output size.
 *
 * @param string $source_path Path to the source image (already containment-checked)
 * @return true|string True if safe to decode, or a human-readable error message if not.
 */
function ccm_tools_webp_check_image_bomb_guard($source_path) {
    $info = @getimagesize($source_path);
    if ($info === false) {
        return __('Could not read image dimensions.', 'ccm-tools');
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    $pixels = $width * $height;

    // Default cap ~50 megapixels; filterable per site.
    $max_pixels = (int) apply_filters('ccm_tools_webp_max_image_pixels', 50000000);

    if ($pixels <= 0 || $pixels > $max_pixels) {
        return sprintf(
            __('Image is %1$dx%2$d (%3$s megapixels), which exceeds the %4$s megapixel conversion limit.', 'ccm-tools'),
            $width,
            $height,
            round($pixels / 1000000, 1),
            round($max_pixels / 1000000, 1)
        );
    }

    // Rough estimate of decoded memory need: width * height * channels *
    // bytes-per-channel * 2 (decode buffer + working copy) — the same rule
    // of thumb used by GD/Imagick sizing guidance.
    $channels = (!empty($info['channels']) && $info['channels'] > 0) ? (int) $info['channels'] : 4;
    $bits_per_channel = !empty($info['bits']) ? (int) $info['bits'] : 8;
    $bytes_per_channel = max(1, (int) ceil($bits_per_channel / 8));
    $estimated_bytes = $width * $height * $channels * $bytes_per_channel * 2;

    $memory_limit = ccm_tools_webp_get_memory_limit_bytes();
    if ($memory_limit > 0) {
        $available = $memory_limit - memory_get_usage(true);
        if ($estimated_bytes > $available) {
            return sprintf(
                __('Image requires an estimated %s of memory to decode, more than is currently available.', 'ccm-tools'),
                size_format($estimated_bytes)
            );
        }
    }

    return true;
}

/**
 * Convert image using ImageMagick
 */
function ccm_tools_webp_convert_with_imagick($source_path, $dest_path, $quality, $result) {
    // Guard against image-bomb inputs before asking ImageMagick to decode
    // anything — getimagesize() above only reads the header.
    $bomb_check = ccm_tools_webp_check_image_bomb_guard($source_path);
    if ($bomb_check !== true) {
        $result['message'] = $bomb_check;
        return $result;
    }

    // Set ImageMagick temp directory to uploads to avoid /tmp/ access restrictions
    // Many hosting providers restrict ImageMagick from using /tmp/ via open_basedir or policy.xml
    $upload_dir = wp_upload_dir();
    $magick_tmp = $upload_dir['basedir'] . '/ccm-webp-temp';
    if (!file_exists($magick_tmp)) {
        wp_mkdir_p($magick_tmp);
    }
    putenv('MAGICK_TMPDIR=' . $magick_tmp);
    putenv('MAGICK_TEMPORARY_PATH=' . $magick_tmp);

    $imagick = new Imagick();

    // Cap the memory/map resources ImageMagick may use for this decode, as a
    // second line of defence behind the pixel-count check above.
    if (defined('Imagick::RESOURCETYPE_MEMORY') && defined('Imagick::RESOURCETYPE_MAP')) {
        $memory_limit_bytes = ccm_tools_webp_get_memory_limit_bytes();
        $imagick_memory_cap = $memory_limit_bytes > 0
            ? (int) min($memory_limit_bytes * 0.5, 256 * 1024 * 1024)
            : 256 * 1024 * 1024;
        $imagick->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, $imagick_memory_cap);
        $imagick->setResourceLimit(Imagick::RESOURCETYPE_MAP, $imagick_memory_cap * 2);
    }

    $imagick->readImage($source_path);

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
    // Guard against image-bomb inputs before decoding the full pixel buffer.
    $bomb_check = ccm_tools_webp_check_image_bomb_guard($source_path);
    if ($bomb_check !== true) {
        $result['message'] = $bomb_check;
        return $result;
    }

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
            if (!$source_image) { break; }
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
 * Filter content to convert <img>/<source> URLs to WebP.
 * This runs AFTER WordPress has generated srcset, so it won't break srcset.
 * Mainly a safety net for content that never reaches the output buffer.
 *
 * @param string $content The content to filter
 * @return string Content with WebP src/srcset URLs
 */
function ccm_tools_webp_filter_content_src($content) {
    $settings = ccm_tools_webp_get_settings();

    // Check if feature is enabled
    if (empty($settings['enabled']) || empty($settings['serve_webp'])) {
        return $content;
    }

    // Check if browser supports WebP
    if (!ccm_tools_webp_browser_supports_webp()) {
        return $content;
    }

    // Don't process in admin, feeds, or REST API
    if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $content;
    }

    return ccm_tools_webp_process_img_tags($content, wp_upload_dir());
}

/**
 * Start output buffering to capture entire page HTML for background image conversion
 * This catches ALL HTML output including theme templates that don't use the_content filter
 */
function ccm_tools_webp_start_output_buffer() {
    // Don't buffer admin, feeds, REST API, or AJAX requests
    if (is_admin() || is_feed() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    
    // Check if browser supports WebP
    if (!ccm_tools_webp_browser_supports_webp()) {
        return;
    }
    
    ob_start('ccm_tools_webp_process_output_buffer');
}

/**
 * End output buffering and flush
 */
function ccm_tools_webp_end_output_buffer() {
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}

/**
 * Process the output buffer to convert images to WebP
 * Handles: background-image URLs, <img> tags (picture tag conversion or src replacement)
 * 
 * @param string $html The entire HTML output
 * @return string Modified HTML with WebP images
 */
function ccm_tools_webp_process_output_buffer($html) {
    $settings = ccm_tools_webp_get_settings();
    $upload_dir = wp_upload_dir();

    // Process background-image URLs if enabled. Off by default; when on,
    // scoped to <style> blocks and style="" attributes only (see
    // ccm_tools_webp_convert_bg_images_in_html() docblock for why).
    if (!empty($settings['convert_bg_images'])) {
        $html = ccm_tools_webp_convert_bg_images_in_html($html);
    }

    // Serve WebP by rewriting <img> and <source> src/srcset in the final HTML.
    // This catches images in theme templates, page builders, and hand-coded
    // <picture> elements that bypass WordPress's srcset filters.
    if (!empty($settings['serve_webp'])) {
        $html = ccm_tools_webp_process_img_tags($html, $upload_dir);
    }

    return $html;
}

/**
 * Convert a local uploads image URL to its WebP counterpart when available.
 *
 * Returns the original URL unchanged when no WebP exists (and can't be created)
 * or when the URL isn't a convertible local upload, so it is always safe to
 * splice back into markup — no broken images.
 *
 * @param string $url       The original image URL
 * @param bool   $on_demand Whether to allow on-demand conversion / queueing
 * @return string WebP URL if available, otherwise the original URL
 */
function ccm_tools_webp_maybe_webp_url($url, $on_demand = true) {
    $url = trim($url);
    if ($url === '') {
        return $url;
    }

    // get_or_create() already skips non-local and already-WebP URLs (returns
    // false / the same URL), so a simple coalesce keeps the original safely.
    $webp = ccm_tools_webp_get_or_create($url, $on_demand);
    return ($webp && $webp !== $url) ? $webp : $url;
}

/**
 * Rewrite every URL in a srcset attribute value to WebP where available.
 *
 * Preserves each candidate's descriptor (e.g. "300w", "2x") and keeps the
 * original URL for any candidate that has no WebP version.
 *
 * @param string $srcset    The srcset attribute value
 * @param bool   $on_demand Whether to allow on-demand conversion / queueing
 * @return string Rewritten srcset value
 */
function ccm_tools_webp_rewrite_srcset($srcset, $on_demand = true) {
    $entries = preg_split('/,\s*/', trim($srcset));
    $out = array();

    foreach ($entries as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }

        // Split into "<url> <descriptor>" — the descriptor is optional.
        if (preg_match('/^(\S+)(\s+.+)?$/s', $entry, $parts)) {
            $webp = ccm_tools_webp_maybe_webp_url($parts[1], $on_demand);
            $out[] = $webp . (isset($parts[2]) ? $parts[2] : '');
        } else {
            $out[] = $entry;
        }
    }

    return implode(', ', $out);
}

/**
 * Rewrite the src/srcset of a single <img> or <source> tag to WebP.
 *
 * All other attributes are left untouched. When the tag carries an explicit
 * image type= hint (e.g. <source type="image/png">) and we swap in WebP, the
 * hint is updated to image/webp so browser source-selection stays correct.
 *
 * @param string $tag A single <img ...> or <source ...> tag
 * @return string The tag with WebP URLs where available
 */
function ccm_tools_webp_rewrite_media_tag($tag) {
    $changed = false;

    // src="..." — quotes are matched by [^"\'] so either quote style works.
    if (preg_match('/(\ssrc=)(["\'])([^"\']*)\2/i', $tag, $m)) {
        $webp = ccm_tools_webp_maybe_webp_url($m[3]);
        if ($webp !== $m[3]) {
            $tag = str_replace($m[0], $m[1] . $m[2] . $webp . $m[2], $tag);
            $changed = true;
        }
    }

    // srcset="..."
    if (preg_match('/(\ssrcset=)(["\'])([^"\']*)\2/i', $tag, $m)) {
        $new_srcset = ccm_tools_webp_rewrite_srcset($m[3]);
        if ($new_srcset !== $m[3]) {
            $tag = str_replace($m[0], $m[1] . $m[2] . $new_srcset . $m[2], $tag);
            $changed = true;
        }
    }

    // Keep an explicit image type hint consistent with the WebP we injected.
    if ($changed && preg_match('/(\stype=)(["\'])image\/(?:png|jpe?g|gif)\2/i', $tag, $m)) {
        $tag = str_replace($m[0], $m[1] . $m[2] . 'image/webp' . $m[2], $tag);
    }

    return $tag;
}

/**
 * Rewrite <img> and <source> tags in HTML to serve WebP (src + srcset).
 *
 * Used by both the output buffer (whole page) and the content filters. Only
 * local uploads with an available WebP are swapped; everything else is left
 * exactly as-is, and the pass is idempotent (already-WebP URLs are skipped),
 * so running it twice is harmless.
 *
 * @param string $html        The HTML content
 * @param array  $upload_dir  The WordPress upload directory info (unused; kept
 *                            for backwards-compatible call sites)
 * @return string Modified HTML with WebP URLs
 */
function ccm_tools_webp_process_img_tags($html, $upload_dir = null) {
    if (stripos($html, '<img') === false && stripos($html, '<source') === false) {
        return $html;
    }

    // <img> tags — rewrite src + srcset.
    $out = preg_replace_callback('/<img\b[^>]*>/i', function($matches) {
        return ccm_tools_webp_rewrite_media_tag($matches[0]);
    }, $html);
    if (null !== $out) { $html = $out; }

    // <source> tags — rewrite src + srcset. This is what fixes hand-coded
    // <picture> elements: the browser picks a matching <source>, not the <img>,
    // so the <source> URLs are the ones that actually need to be WebP.
    $out = preg_replace_callback('/<source\b[^>]*>/i', function($matches) {
        return ccm_tools_webp_rewrite_media_tag($matches[0]);
    }, $html);
    if (null !== $out) { $html = $out; }

    return $html;
}

/**
 * Rewrite background-image url(...) references to WebP, scoped to <style>
 * blocks and style="" attributes only.
 *
 * The previous implementation ran its url() regex over the ENTIRE raw page
 * HTML, so a url(x.jpg)-shaped substring inside an inline <script> string
 * literal or SVG <defs> would be silently rewritten too. Restricting the
 * pass to actual CSS contexts (style blocks + style attributes) means it
 * only ever touches real CSS.
 *
 * @param string $html The HTML content
 * @return string HTML with WebP background-image URLs where available
 */
function ccm_tools_webp_convert_bg_images_in_html($html) {
    if (stripos($html, 'url(') === false) {
        return $html;
    }

    // Pattern matches url() containing image URLs — supports both absolute
    // URLs (https://example.com/...) and relative paths
    // (/wp-content/uploads/...). Captures: 1=opening quote (if any), 2=URL
    $url_pattern = '/url\s*\(\s*(["\']?)([^"\')\s]+\.(?:jpg|jpeg|png|gif))\1\s*\)/i';

    /*
     * Every preg_* in this output path falls back to its untouched input.
     * PCRE returns null once it hits pcre.backtrack_limit, and the <style>
     * pattern below backtracks once per character, so a page over about a
     * megabyte containing the substring "<style" exhausts it. Passing that
     * null on hands the output buffer nothing and every visitor gets a blank
     * page — which an administrator never sees, because this whole filter is
     * skipped for them.
     */
    $rewrite_css_urls = function($css) use ($url_pattern) {
        $out = preg_replace_callback($url_pattern, function($matches) {
            $quote = $matches[1]; // Preserve original quote style (empty, ', or ")
            $original_url = $matches[2];

            // Only touch local uploads.
            if (strpos($original_url, '/wp-content/uploads/') === false) {
                return $matches[0];
            }

            $webp_url = ccm_tools_webp_get_or_create($original_url);

            if ($webp_url && $webp_url !== $original_url) {
                return 'url(' . $quote . $webp_url . $quote . ')';
            }

            return $matches[0];
        }, $css);

        return (null === $out) ? $css : $out;
    };

    // <style>...</style> blocks
    $out = preg_replace_callback('/<style\b[^>]*>([\s\S]*?)<\/style>/i', function($matches) use ($rewrite_css_urls) {
        return str_replace($matches[1], $rewrite_css_urls($matches[1]), $matches[0]);
    }, $html);
    if (null !== $out) { $html = $out; }

    // style="..." attributes on any tag
    $out = preg_replace_callback('/(\sstyle=)(["\'])([^"\']*)\2/i', function($matches) use ($rewrite_css_urls) {
        return $matches[1] . $matches[2] . $rewrite_css_urls($matches[3]) . $matches[2];
    }, $html);
    if (null !== $out) { $html = $out; }

    return $html;
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

    // Resolve to a real, contained filesystem path — rejects traversal
    // attempts and anything outside the uploads directory. $must_exist=true
    // because there's nothing to queue if the source file isn't real.
    $original_path = ccm_tools_webp_url_to_safe_path($original_url, $upload_dir, true);
    if ($original_path === false) {
        return;
    }

    // Generate WebP path
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
    $settings = ccm_tools_webp_get_settings();

    // Resolve to a real, contained filesystem path — rejects traversal
    // attempts and anything outside the uploads directory. This is the
    // anonymous-request path (wp_calculate_image_srcset + the output
    // buffer), so containment here is what stops a crafted <img src="">
    // anywhere on the site from reading/writing outside uploads.
    $original_path = ccm_tools_webp_url_to_safe_path($original_url, $upload_dir, true);
    if ($original_path === false) {
        return false;
    }

    // Generate WebP path/URL from the already-validated source path.
    $webp_path = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $original_path);
    $webp_url = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $original_url);

    // Check if WebP exists
    if (file_exists($webp_path)) {
        return $webp_url;
    }

    // Try synchronous on-demand conversion if enabled
    if (!empty($settings['convert_on_demand'])) {
        $failed_key = 'ccm_webp_failed_' . md5($original_path);
        $lock_key = 'ccm_webp_lock_' . md5($original_path);

        // Per-file in-progress marker: if another request is already
        // converting this exact file, don't pile on — fall through to the
        // queue/original below instead of doing a second decode+encode.
        if (!get_transient($failed_key) && !get_transient($lock_key)) {
            // Site-wide rate limit: cap on-demand conversions per minute so
            // an anonymous crawl of a large media library can't force
            // unlimited full decode+encode cycles in-request. Once over the
            // cap for this minute, fall back to serving the original and
            // queue for background processing instead.
            $rate_key = 'ccm_webp_ondemand_count_' . gmdate('YmdHi');
            $count = (int) get_transient($rate_key);
            $max_per_minute = (int) apply_filters('ccm_tools_webp_max_conversions_per_minute', 20);

            if ($count < $max_per_minute) {
                set_transient($rate_key, $count + 1, 60);
                set_transient($lock_key, true, 30); // 30s is generous for a single conversion

                $quality = intval($settings['quality']);
                $extension = $settings['preferred_extension'];

                $result = ccm_tools_webp_convert_image($original_path, $webp_path, $quality, $extension);

                delete_transient($lock_key);

                if ($result['success']) {
                    return $webp_url;
                } else {
                    // Mark as failed to avoid repeated attempts (cache for 1 hour)
                    set_transient($failed_key, true, HOUR_IN_SECONDS);
                }
            }
        }
    }

    // Queue for background conversion if still not converted
    if ($queue_if_missing) {
        ccm_tools_webp_queue_for_conversion($original_url);
    }

    return false;
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
 * Checks actual WebP files on disk for all image sizes (full + thumbnails).
 * This ensures accurate stats even if WebP files were created externally.
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
    
    // Get all images from media library (limit for performance)
    $attachments = $wpdb->get_results(
        "SELECT ID FROM {$wpdb->posts} 
         WHERE post_type = 'attachment' 
         AND post_mime_type IN ('image/jpeg', 'image/png', 'image/gif')
         ORDER BY ID DESC
         LIMIT 2000"
    );
    
    // Track all image files (full size + all thumbnails)
    $all_images = array();
    $converted_count = 0;
    
    foreach ($attachments as $attachment) {
        $file_path = get_attached_file($attachment->ID);
        
        if (!$file_path || !file_exists($file_path)) {
            continue;
        }
        
        $file_dir = dirname($file_path);
        
        // Add full-size image
        $all_images[] = array(
            'path' => $file_path,
            'webp_path' => preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $file_path)
        );
        
        // Get attachment metadata for thumbnails
        $metadata = wp_get_attachment_metadata($attachment->ID);
        
        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                if (!empty($size_data['file'])) {
                    $thumb_path = $file_dir . '/' . $size_data['file'];
                    if (file_exists($thumb_path)) {
                        $all_images[] = array(
                            'path' => $thumb_path,
                            'webp_path' => preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $thumb_path)
                        );
                    }
                }
            }
        }
    }
    
    $stats['total_images'] = count($all_images);
    
    // Check each image for WebP version
    foreach ($all_images as $image) {
        if (file_exists($image['webp_path'])) {
            $converted_count++;
            
            // Calculate sizes for savings
            $original_size = @filesize($image['path']);
            $webp_size = @filesize($image['webp_path']);
            
            if ($original_size && $webp_size) {
                $stats['total_original_size'] += $original_size;
                $stats['total_webp_size'] += $webp_size;
            }
        }
    }
    
    $stats['converted_images'] = $converted_count;
    
    // Calculate pending
    $stats['pending_conversion'] = max(0, $stats['total_images'] - $stats['converted_images']);
    
    // Calculate total savings percentage
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
        // NOTE: Removed wp_get_attachment_image_src filter
        // Changing src before srcset calculation breaks WordPress's srcset generation
        // because WordPress compares src to metadata and returns empty srcset if they don't match
        // Instead, we convert URLs in the final HTML output via the filters below + output buffer

        // Rewrite <img>/<source> src + srcset to WebP in rendered content.
        // Runs AFTER WordPress has generated the srcset. The output buffer below
        // covers the whole page; these filters also catch content that renders
        // outside the main buffer (e.g. AJAX-loaded fragments).
        add_filter('the_content', 'ccm_tools_webp_filter_content_src', 1000);
        add_filter('widget_text', 'ccm_tools_webp_filter_content_src', 1000);
        add_filter('widget_block_content', 'ccm_tools_webp_filter_content_src', 1000);
    }

    // Use output buffering to catch ALL HTML including theme templates
    // This is necessary because images in page builders, custom themes, etc. don't go through the_content filter
    // Enable when either serve_webp or convert_bg_images is enabled
    if (!empty($settings['serve_webp']) || !empty($settings['convert_bg_images'])) {
        add_action('template_redirect', 'ccm_tools_webp_start_output_buffer', 1);
        add_action('shutdown', 'ccm_tools_webp_end_output_buffer', 0);

        // Add Vary: Accept header so CDNs/proxies cache WebP and non-WebP versions separately
        add_action('template_redirect', function() {
            if (!is_admin()) {
                header('Vary: Accept', false);
            }
        });
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
                body: 'action=ccm_tools_process_webp_queue&nonce=<?php echo wp_create_nonce('ccm-tools-nonce'); ?>'
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
 *
 * Reading order: the hero says what will do the converting and whether WebP
 * is actually being served right now; the stat grid gives the one number
 * anyone actually wants (how much this has saved); bulk conversion and
 * settings are the two things this page exists to operate; everything else
 * (which library is doing the work, a one-image test, import/export, an
 * uploads backup) is detail needed rarely, so it stays compact or tucked
 * into a disclosure.
 *
 * Containers: settings are one `.ccm-optgroup` card, the same component the
 * .htaccess and Performance pages use, with the group name, its one line of
 * context and the live count all in the card header rather than floating
 * above the list. Bulk conversion keeps a full-width `.ccm-panel` because it
 * is the primary action here. The two reference blocks — which library is
 * present, and the one-image test — share a `.ccm-grid-2` row, which drops
 * to one column on a narrow screen.
 *
 * @return void
 */
/**
 * Trim an image library's version down to the actual version number.
 *
 * Imagick::getVersion() returns a whole sentence, e.g. "ImageMagick 7.1.1-29
 * Q16-HDRI x86_64 22128 https://imagemagick.org". Printing that after the
 * library name gives "ImageMagick ImageMagick 7.1.1-29 Q16-HDRI ..." and a URL
 * in the page heading, so pull out the number and drop the rest.
 *
 * @param string $version Raw version string as the extension reported it.
 * @return string Just the version number, or '' when none can be found.
 */
function ccm_tools_webp_clean_version($version) {
    $version = trim((string) $version);
    if ($version === '' || strtolower($version) === 'unknown') {
        return '';
    }
    if (preg_match('/(\d+\.\d+[0-9A-Za-z.\-]*)/', $version, $m)) {
        return $m[1];
    }
    return $version;
}

/**
 * Render an image library as "Name 7.1.1-29", with no repeated name.
 *
 * @param array $ext One entry from ccm_tools_webp_get_available_extensions().
 * @return string
 */
function ccm_tools_webp_library_label($ext) {
    $name    = isset($ext['name']) ? trim((string) $ext['name']) : '';
    $version = ccm_tools_webp_clean_version(isset($ext['version']) ? $ext['version'] : '');
    return trim($name . ' ' . $version);
}

function ccm_tools_render_webp_page() {
    $available      = ccm_tools_webp_is_available();
    $extensions     = ccm_tools_webp_get_available_extensions();
    $settings       = ccm_tools_webp_get_settings();
    $stats          = ccm_tools_webp_get_statistics();
    $best_extension = ccm_tools_webp_get_best_extension();

    // Which library will actually do the conversion: an explicit preference
    // wins over the auto-picked best, provided it is one that really exists.
    $preferred  = $settings['preferred_extension'];
    $active_key = ($preferred !== 'auto' && isset($extensions[$preferred])) ? $preferred : $best_extension;
    $active     = ($active_key && isset($extensions[$active_key])) ? $extensions[$active_key] : null;

    // WebP is only actually reaching visitors if both the master switch and
    // the serve toggle are on; ccm_tools_webp_init() requires both.
    $serving = !empty($settings['enabled']) && !empty($settings['serve_webp']);

    // Tally for the Settings section eyebrow, same shape as the Performance page.
    $opt_keys = array('serve_webp', 'convert_on_upload', 'convert_on_demand', 'convert_bg_images', 'keep_originals');
    $opts_on  = 0;
    foreach ($opt_keys as $opt_key) {
        if (!empty($settings[$opt_key])) { $opts_on++; }
    }

    $zip_available = class_exists('ZipArchive') || extension_loaded('zip');
    ?>
    <div class="wrap ccm-tools ccm-tools-webp">
        <?php
        if (function_exists('ccm_tools_render_header_nav')) {
            ccm_tools_render_header_nav('ccm-tools-webp');
        }
        ?>

        <div class="ccm-content">

            <?php if (!$available) : ?>
                <div class="ccm-alert ccm-alert--bad" style="margin-bottom: var(--ccm-space-lg);">
                    <span class="ccm-dot ccm-dot-bad"></span>
                    <div>
                        <strong><?php _e('No image library on this server can create WebP files.', 'ccm-tools'); ?></strong>
                        <?php _e('Neither GD nor ImageMagick with WebP support was found, so nothing below this notice will work. Ask your hosting provider to enable one of them.', 'ccm-tools'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Hero -->
            <div class="ccm-hero">
                <div class="ccm-hero__text">
                    <h1><?php _e('WebP Converter', 'ccm-tools'); ?></h1>
                    <div class="ccm-hero__meta">
                        <span><?php echo $active
                            ? esc_html(ccm_tools_webp_library_label($active))
                            : esc_html__('No WebP-capable library', 'ccm-tools'); ?></span>
                        <span><?php echo $serving
                            ? esc_html__('serving WebP to capable browsers', 'ccm-tools')
                            : esc_html__('not currently serving WebP', 'ccm-tools'); ?></span>
                    </div>
                </div>
                <?php if ($available) : ?>
                <div class="ccm-hero__actions">
                    <span class="ccm-masterswitch<?php echo !empty($settings['enabled']) ? ' is-on' : ''; ?>">
                        <span class="ccm-masterswitch__label">
                            <?php echo !empty($settings['enabled'])
                                ? esc_html__('Conversion on', 'ccm-tools')
                                : esc_html__('Conversion off', 'ccm-tools'); ?>
                        </span>
                        <label class="ccm-toggle">
                            <input type="checkbox" name="enabled" id="webp-enabled" value="1" <?php checked($settings['enabled'], true); ?>>
                            <span class="ccm-toggle-slider"></span>
                        </label>
                    </span>
                    <button type="button" id="start-bulk-conversion" class="ccm-button ccm-button-primary" <?php disabled($stats['pending_conversion'] === 0); ?>>
                        <?php _e('Start bulk conversion', 'ccm-tools'); ?>
                    </button>
                    <button type="button" id="stop-bulk-conversion" class="ccm-button ccm-button-danger" style="display: none;">
                        <?php _e('Stop conversion', 'ccm-tools'); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($available) : ?>

            <!-- Stat grid: the saving percentage is the headline of this page -->
            <div class="ccm-stat-grid" id="webp-stats-card">
                <div class="ccm-stat-tile">
                    <div class="ccm-stat-tile__value">
                        <span id="stat-converted-images"><?php echo esc_html($stats['converted_images']); ?></span>
                        <small>/ <span id="stat-total-images"><?php echo esc_html($stats['total_images']); ?></span></small>
                    </div>
                    <div class="ccm-stat-tile__label"><?php _e('Converted to WebP', 'ccm-tools'); ?></div>
                </div>
                <div class="ccm-stat-tile">
                    <div class="ccm-stat-tile__value" id="stat-original-size"><?php echo esc_html(size_format($stats['total_original_size'])); ?></div>
                    <div class="ccm-stat-tile__label"><?php _e('Original size', 'ccm-tools'); ?></div>
                </div>
                <div class="ccm-stat-tile">
                    <div class="ccm-stat-tile__value" id="stat-webp-size"><?php echo esc_html(size_format($stats['total_webp_size'])); ?></div>
                    <div class="ccm-stat-tile__label"><?php _e('WebP size', 'ccm-tools'); ?></div>
                </div>
                <div class="ccm-stat-tile">
                    <div class="ccm-stat-tile__value ccm-stat-tile__value--brand" id="stat-average-savings"><?php echo esc_html($stats['total_savings']); ?>%</div>
                    <div class="ccm-stat-tile__label"><?php _e('Average saving', 'ccm-tools'); ?></div>
                    <div class="ccm-stat-tile__sub" id="stat-size-comparison"<?php echo $stats['total_original_size'] > 0 ? '' : ' style="display:none;"'; ?>>
                        <span id="stat-saved-size"><?php echo esc_html(sprintf(
                            /* translators: %s: amount of disk space saved, already formatted (e.g. "2.1 MB") */
                            __('Saved %s', 'ccm-tools'),
                            size_format($stats['total_original_size'] - $stats['total_webp_size'])
                        )); ?></span>
                    </div>
                </div>
            </div>

            <!-- Bulk conversion: the main thing this page does, so it stays
                 full width rather than sharing a row with anything. -->
            <div class="ccm-panel" style="margin-bottom: var(--ccm-space-xl);">
                <div class="ccm-panel__head">
                    <span><?php _e('Bulk conversion', 'ccm-tools'); ?></span>
                    <span class="ccm-text-muted" style="font-size: var(--ccm-text-xs);"><?php echo esc_html(sprintf(
                        /* translators: 1: converted count, 2: total eligible count */
                        __('%1$d of %2$d converted', 'ccm-tools'),
                        $stats['converted_images'],
                        $stats['total_images']
                    )); ?></span>
                </div>
                <div class="ccm-panel__body">

                    <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-md);">
                        <?php _e('Converts every eligible image already in the media library. Regenerating deletes the existing WebP files first and redoes them with the current quality and library settings.', 'ccm-tools'); ?>
                    </p>

                    <div id="webp-bulk-idle">
                        <p style="margin: 0 0 var(--ccm-space-md);">
                            <?php if ($stats['pending_conversion'] > 0) : ?>
                                <?php printf(
                                    /* translators: %s: number of images waiting, wrapped in a strong tag with an id main.js updates live */
                                    esc_html__('%s images are waiting to convert. Use Start bulk conversion above to run them now.', 'ccm-tools'),
                                    '<strong id="stat-pending-images">' . esc_html($stats['pending_conversion']) . '</strong>'
                                ); ?>
                            <?php else : ?>
                                <?php esc_html_e('Every eligible image already has a WebP version.', 'ccm-tools'); ?>
                                <span id="stat-pending-images" class="ccm-hide">0</span>
                            <?php endif; ?>
                        </p>
                        <button type="button" id="regenerate-all-webp" class="ccm-button ccm-button-secondary ccm-button-small" <?php disabled($stats['converted_images'] === 0); ?>>
                            <?php echo esc_html(sprintf(
                                /* translators: %d: number of already-converted images */
                                __('Regenerate %d WebP Images', 'ccm-tools'),
                                $stats['converted_images']
                            )); ?>
                        </button>
                    </div>

                    <div id="bulk-conversion-progress" style="display: none;">
                        <div class="ccm-row" style="justify-content: space-between; margin-bottom: var(--ccm-space-xs);">
                            <span><?php _e('Converting', 'ccm-tools'); ?> <span id="bulk-current">0</span> / <span id="bulk-total">0</span></span>
                        </div>
                        <div class="ccm-meter"><i id="bulk-progress-bar" style="width: 0%;"></i></div>
                        <div id="bulk-conversion-log" class="ccm-log-box" aria-live="polite"></div>
                    </div>

                </div>
            </div>

            <!-- Settings: one contained group, so the quality field and the
                 toggles it applies to sit in the same card. -->
            <form id="webp-settings-form">
                <section class="ccm-optgroup" data-group="settings">
                    <header class="ccm-optgroup__head">
                        <div>
                            <h2 class="ccm-optgroup__title"><?php _e('Settings', 'ccm-tools'); ?></h2>
                            <p class="ccm-optgroup__note"><?php _e('The master switch above turns all of this on or off. These control what happens while it is on.', 'ccm-tools'); ?></p>
                        </div>
                        <span class="ccm-optgroup__count" data-group-count><?php echo esc_html(sprintf(
                            /* translators: 1: enabled count, 2: total count */
                            __('%1$d of %2$d on', 'ccm-tools'), $opts_on, count($opt_keys)
                        )); ?></span>
                    </header>
                    <div class="ccm-optgroup__body">
                        <div class="ccm-opt">
                            <div class="ccm-opt__main">
                                <div class="ccm-opt__text">
                                    <label class="ccm-opt__label" for="webp-quality"><?php _e('Quality', 'ccm-tools'); ?></label>
                                    <p class="ccm-opt__desc"><?php _e('85 is the default and is close to lossless. Push it past 90 and the file barely shrinks for the extra size.', 'ccm-tools'); ?></p>
                                </div>
                                <span class="ccm-optfield__inline">
                                    <input type="number" name="quality" id="webp-quality" class="ccm-input"
                                           min="1" max="100" value="<?php echo esc_attr($settings['quality']); ?>">
                                    <span class="ccm-optfield__suffix"><?php _e('/ 100', 'ccm-tools'); ?></span>
                                </span>
                            </div>
                        </div>

                        <div class="ccm-opt<?php echo !empty($settings['serve_webp']) ? ' is-on' : ''; ?>">
                            <div class="ccm-opt__main">
                                <div class="ccm-opt__text">
                                    <span class="ccm-opt__label"><?php _e('Serve WebP to capable browsers', 'ccm-tools'); ?></span>
                                    <p class="ccm-opt__desc"><?php _e('Rewrites image URLs to the WebP version for browsers that support it. Anything older still gets the original file.', 'ccm-tools'); ?></p>
                                </div>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="serve_webp" id="webp-serve" value="1" <?php checked($settings['serve_webp'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="ccm-opt<?php echo !empty($settings['convert_on_upload']) ? ' is-on' : ''; ?>">
                            <div class="ccm-opt__main">
                                <div class="ccm-opt__text">
                                    <span class="ccm-opt__label"><?php _e('Convert on upload', 'ccm-tools'); ?></span>
                                    <p class="ccm-opt__desc"><?php _e('Creates the WebP version the moment an image is added to the media library, so new uploads never join the backlog.', 'ccm-tools'); ?></p>
                                </div>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="convert_on_upload" id="webp-convert-on-upload" value="1" <?php checked($settings['convert_on_upload'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="ccm-opt<?php echo !empty($settings['convert_on_demand']) ? ' is-on' : ''; ?>">
                            <div class="ccm-opt__main">
                                <div class="ccm-opt__text">
                                    <span class="ccm-opt__label"><?php _e('Convert on demand', 'ccm-tools'); ?></span>
                                    <p class="ccm-opt__desc"><?php _e('Converts an image during a visitor\'s request, the first time it is actually needed, instead of waiting for a bulk run. That first request is a little slower while the conversion happens, but nothing is left unconverted.', 'ccm-tools'); ?></p>
                                </div>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="convert_on_demand" id="webp-convert-on-demand" value="1" <?php checked($settings['convert_on_demand'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="ccm-opt<?php echo !empty($settings['keep_originals']) ? ' is-on' : ''; ?>">
                            <div class="ccm-opt__main">
                                <div class="ccm-opt__text">
                                    <span class="ccm-opt__label"><?php _e('Keep original files', 'ccm-tools'); ?></span>
                                    <p class="ccm-opt__desc"><?php _e('Leaves the original JPG, PNG or GIF in place next to the WebP version. Turning this off removes the fallback that older browsers need.', 'ccm-tools'); ?></p>
                                </div>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="keep_originals" id="webp-keep-originals" value="1" <?php checked($settings['keep_originals'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="ccm-opt<?php echo !empty($settings['convert_bg_images']) ? ' is-on' : ''; ?>">
                            <div class="ccm-opt__main">
                                <div class="ccm-opt__text">
                                    <span class="ccm-opt__label"><?php _e('Convert background images', 'ccm-tools'); ?></span>
                                    <p class="ccm-opt__desc"><?php echo esc_html__('Rewrites background-image URLs to WebP in inline styles and style blocks, which covers most page builders. Only touches images already in this site\'s uploads folder.', 'ccm-tools'); ?></p>
                                </div>
                                <label class="ccm-toggle">
                                    <input type="checkbox" name="convert_bg_images" id="webp-bg-images" value="1" <?php checked($settings['convert_bg_images'], true); ?>>
                                    <span class="ccm-toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="ccm-row" style="margin-top: var(--ccm-space-md);">
                    <button type="submit" id="save-webp-settings" class="ccm-button ccm-button-primary">
                        <?php _e('Save settings', 'ccm-tools'); ?>
                    </button>
                </div>
            </form>

            <?php endif; // if ($available) ?>

            <!-- Reference detail rather than decisions: which library does the
                 work, and a one-image spot check. The two share a row on a wide
                 screen and drop to one column on a narrow one. -->
            <div class="ccm-grid-2" style="margin-top: var(--ccm-space-xl);">

                <div class="ccm-panel">
                    <div class="ccm-panel__head">
                        <span><?php _e('Image processing', 'ccm-tools'); ?></span>
                        <span class="ccm-row">
                            <span class="ccm-text-muted" style="font-size: var(--ccm-text-xs);"><?php echo esc_html(sprintf(
                                /* translators: %d: number of image processing extensions detected on this server */
                                _n('%d extension detected', '%d extensions detected', count($extensions), 'ccm-tools'),
                                count($extensions)
                            )); ?></span>
                            <?php if ($active) : ?>
                                <span class="ccm-chip ccm-chip--good"><?php echo esc_html(sprintf(
                                    /* translators: %s: name of the image library actually in use, e.g. "ImageMagick" */
                                    __('Using %s', 'ccm-tools'), $active['name']
                                )); ?></span>
                            <?php else : ?>
                                <span class="ccm-chip ccm-chip--bad"><?php _e('None available', 'ccm-tools'); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="ccm-panel__body">
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0;">
                            <?php _e('The PHP extension that creates WebP files, and which one this site prefers when more than one is available.', 'ccm-tools'); ?>
                        </p>
                    </div>
                    <div class="ccm-kv">
                        <?php if (empty($extensions)) : ?>
                            <div>
                                <span class="ccm-kv__k"><?php _e('Extensions', 'ccm-tools'); ?></span>
                                <span class="ccm-kv__v ccm-text-muted"><?php _e('Neither GD nor ImageMagick is loaded on this server.', 'ccm-tools'); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($extensions as $ext_name => $ext) : ?>
                            <div>
                                <span class="ccm-kv__k"><?php echo esc_html($ext['name']); ?></span>
                                <span class="ccm-kv__v">
                                    <?php echo esc_html(sprintf(
                                        /* translators: %s: version string reported by the library */
                                        __('Version %s', 'ccm-tools'), ccm_tools_webp_clean_version($ext['version'])
                                    )); ?>
                                    <span class="ccm-chip<?php echo $ext['webp_support'] ? ' ccm-chip--good' : ' ccm-chip--bad'; ?>">
                                        <?php echo $ext['webp_support'] ? esc_html__('WebP', 'ccm-tools') : esc_html__('No WebP', 'ccm-tools'); ?>
                                    </span>
                                    <small><?php echo esc_html(sprintf(
                                        /* translators: 1: JPEG supported tick/cross, 2: PNG supported tick/cross, 3: GIF supported tick/cross */
                                        __('Also reads JPEG %1$s, PNG %2$s, GIF %3$s', 'ccm-tools'),
                                        $ext['jpeg_support'] ? '✓' : '✗',
                                        $ext['png_support'] ? '✓' : '✗',
                                        $ext['gif_support'] ? '✓' : '✗'
                                    )); ?></small>
                                </span>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($available) : ?>
                            <div>
                                <span class="ccm-kv__k"><?php _e('Preferred library', 'ccm-tools'); ?></span>
                                <span class="ccm-kv__v">
                                    <select name="preferred_extension" id="webp-preferred-extension" aria-label="<?php esc_attr_e('Preferred image library', 'ccm-tools'); ?>">
                                        <option value="auto" <?php selected($settings['preferred_extension'], 'auto'); ?>><?php _e('Auto (best available)', 'ccm-tools'); ?></option>
                                        <?php foreach ($extensions as $ext_name => $ext) : ?>
                                            <?php if ($ext['webp_support']) : ?>
                                                <option value="<?php echo esc_attr($ext_name); ?>" <?php selected($settings['preferred_extension'], $ext_name); ?>>
                                                    <?php echo esc_html($ext['name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <small><?php _e('Auto prefers ImageMagick over GD when both are present.', 'ccm-tools'); ?></small>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($available) : ?>
                <div class="ccm-panel">
                    <div class="ccm-panel__head">
                        <span><?php _e('Test conversion', 'ccm-tools'); ?></span>
                    </div>
                    <div class="ccm-panel__body">
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-md);">
                            <?php _e('Converts one image with the current quality and library settings, and shows the before and after sizes.', 'ccm-tools'); ?>
                        </p>
                        <div class="ccm-row">
                            <input type="file" id="test-image-upload" accept="image/jpeg,image/png,image/gif" class="ccm-hide" aria-label="<?php esc_attr_e('Choose an image to test convert', 'ccm-tools'); ?>">
                            <button type="button" id="select-test-image" class="ccm-button ccm-button-secondary ccm-button-small">
                                <?php _e('Choose an image', 'ccm-tools'); ?>
                            </button>
                            <span id="test-image-name" class="ccm-text-muted" style="font-size: var(--ccm-text-sm);"></span>
                            <span class="ccm-toolbar__spacer"></span>
                            <button type="button" id="run-test-conversion" class="ccm-button ccm-button-primary ccm-button-small" disabled>
                                <?php _e('Convert', 'ccm-tools'); ?>
                            </button>
                        </div>
                        <div id="test-conversion-result" style="display: none; margin-top: var(--ccm-space-md);">
                            <div id="test-result-content"></div>
                        </div>
                    </div>
                </div>
                <?php endif; // if ($available) ?>

            </div>

            <!-- Housekeeping -->
            <div class="ccm-section">
                <div>
                    <span class="ccm-section__eyebrow"><?php _e('Occasional', 'ccm-tools'); ?></span>
                    <h2><?php _e('Housekeeping', 'ccm-tools'); ?></h2>
                    <p><?php _e('Carry settings between sites, and back up before a large run.', 'ccm-tools'); ?></p>
                </div>
            </div>

            <div class="ccm-stack ccm-stack--sm">

                <?php if ($available) : ?>
                <details class="ccm-disclose">
                    <summary><?php _e('Import or export these settings', 'ccm-tools'); ?></summary>
                    <div class="ccm-disclose__body">
                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-md);">
                            <?php _e('Copy this configuration to or from another site.', 'ccm-tools'); ?>
                        </p>
                        <div class="ccm-grid-2">
                            <div>
                                <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-sm);"><?php _e('Download the current settings as a JSON file.', 'ccm-tools'); ?></p>
                                <button type="button" id="export-webp-settings" class="ccm-button ccm-button-secondary ccm-button-small">
                                    📥 <?php _e('Export Settings', 'ccm-tools'); ?>
                                </button>
                            </div>
                            <div>
                                <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-sm);"><?php _e('Load settings that were exported from another site.', 'ccm-tools'); ?></p>
                                <input type="file" id="import-webp-settings-file" accept=".json" class="ccm-hide" aria-label="<?php esc_attr_e('Choose a WebP settings file to import', 'ccm-tools'); ?>">
                                <button type="button" id="import-webp-settings-btn" class="ccm-button ccm-button-secondary ccm-button-small">
                                    <?php _e('Choose file', 'ccm-tools'); ?>
                                </button>
                                <span id="import-webp-file-name" class="ccm-text-muted" style="font-size: var(--ccm-text-xs); margin-left: var(--ccm-space-sm);"></span>
                                <button type="button" id="import-webp-settings" class="ccm-button ccm-button-primary ccm-button-small" style="display: none; margin-top: var(--ccm-space-sm);">
                                    <?php _e('Import Settings', 'ccm-tools'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </details>
                <?php endif; // if ($available) ?>

                <details class="ccm-disclose">
                    <summary><?php _e('Uploads folder backup', 'ccm-tools'); ?></summary>
                    <div class="ccm-disclose__body">
                        <?php if ($zip_available) : ?>
                            <p class="ccm-text-muted" style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-md);">
                                <?php _e('Creates a downloadable ZIP of the entire uploads folder, useful before a large bulk conversion.', 'ccm-tools'); ?>
                            </p>
                            <div id="backup-info" style="margin-bottom: var(--ccm-space-md); font-size: var(--ccm-text-sm);">
                                <p><?php _e('Loading uploads information…', 'ccm-tools'); ?></p>
                            </div>
                            <div class="ccm-row">
                                <button type="button" id="start-uploads-backup" class="ccm-button ccm-button-primary ccm-button-small">
                                    <?php _e('Create backup', 'ccm-tools'); ?>
                                </button>
                                <button type="button" id="cancel-uploads-backup" class="ccm-button ccm-button-danger ccm-button-small" style="display: none;">
                                    <?php _e('Cancel', 'ccm-tools'); ?>
                                </button>
                            </div>
                            <div id="backup-progress" style="display: none; margin-top: var(--ccm-space-md);">
                                <p style="font-size: var(--ccm-text-sm); margin: 0 0 var(--ccm-space-xs);">
                                    <?php _e('Processing', 'ccm-tools'); ?>
                                    <span id="backup-current">0</span>/<span id="backup-total">0</span> <?php _e('files', 'ccm-tools'); ?>
                                    (<span id="backup-percent">0</span>%)
                                </p>
                                <div class="ccm-meter"><i id="backup-progress-bar" style="width: 0%;"></i></div>
                            </div>
                            <div id="backup-complete" style="display: none; margin-top: var(--ccm-space-md);">
                                <div class="ccm-alert ccm-alert--good">
                                    <span class="ccm-dot ccm-dot-ok"></span>
                                    <div>
                                        <strong><?php _e('Backup complete.', 'ccm-tools'); ?></strong>
                                        <?php _e('File size:', 'ccm-tools'); ?> <span id="backup-size"></span>
                                        <div class="ccm-row" style="margin-top: var(--ccm-space-sm);">
                                            <a href="#" id="download-backup" class="ccm-button ccm-button-primary ccm-button-small"><?php _e('Download', 'ccm-tools'); ?></a>
                                            <button type="button" id="delete-backup" class="ccm-button ccm-button-danger ccm-button-small"><?php _e('Delete backup', 'ccm-tools'); ?></button>
                                        </div>
                                        <p class="ccm-text-muted" style="font-size: var(--ccm-text-xs); margin: var(--ccm-space-sm) 0 0;">
                                            <?php _e('Deleted automatically after 24 hours.', 'ccm-tools'); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="ccm-alert ccm-alert--warn">
                                <span class="ccm-dot ccm-dot-warn"></span>
                                <div>
                                    <strong><?php _e('ZipArchive is not available.', 'ccm-tools'); ?></strong>
                                    <?php _e('The PHP zip extension is not installed on this server, so a backup cannot be created here. Ask your hosting provider to enable it.', 'ccm-tools'); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>

            </div>

        </div>

        <?php if ($available) : ?>
        <div class="ccm-savebar" data-ccm-savebar data-savebar-target="#save-webp-settings">
            <span class="ccm-savebar__dot" aria-hidden="true"></span>
            <span class="ccm-savebar__msg"><?php _e('No unsaved changes', 'ccm-tools'); ?></span>
            <button type="button" class="ccm-button ccm-button-secondary ccm-button-small" data-savebar-discard>
                <?php _e('Discard', 'ccm-tools'); ?>
            </button>
            <button type="button" class="ccm-button ccm-button-primary" data-savebar-save>
                <?php _e('Save settings', 'ccm-tools'); ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
