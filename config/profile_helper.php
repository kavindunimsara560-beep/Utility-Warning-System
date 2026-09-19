<?php
// Balangoda Utility System - Profile & Avatar Helper Functions

/**
 * Ensures table columns for profile picture & admin details exist.
 * Safe to call; silently handles duplicates.
 */
function ensure_profile_schema($conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    // Check customers.profile_pic
    $c_check = $conn->query("SHOW COLUMNS FROM `customers` LIKE 'profile_pic'");
    if ($c_check && $c_check->num_rows === 0) {
        @$conn->query("ALTER TABLE `customers` ADD COLUMN `profile_pic` VARCHAR(255) NULL DEFAULT NULL AFTER `address`");
    }

    // Check admins columns
    $a_check = $conn->query("SHOW COLUMNS FROM `admins` LIKE 'profile_pic'");
    if ($a_check && $a_check->num_rows === 0) {
        @$conn->query("ALTER TABLE `admins` ADD COLUMN `full_name` VARCHAR(100) NULL DEFAULT NULL AFTER `username`");
        @$conn->query("ALTER TABLE `admins` ADD COLUMN `phone` VARCHAR(20) NULL DEFAULT NULL AFTER `email`");
        @$conn->query("ALTER TABLE `admins` ADD COLUMN `address` TEXT NULL DEFAULT NULL AFTER `phone`");
        @$conn->query("ALTER TABLE `admins` ADD COLUMN `profile_pic` VARCHAR(255) NULL DEFAULT NULL AFTER `address`");
    }
}

/**
 * Handles avatar image upload securely.
 *
 * @param array $file $_FILES['avatar']
 * @param string $prefix e.g. 'cust' or 'admin'
 * @param int $user_id
 * @param string|null $old_avatar Previous relative path to delete
 * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
 */
function handle_avatar_upload($file, $prefix, $user_id, $old_avatar = null) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'path' => null, 'error' => 'No file uploaded.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'path' => null, 'error' => 'File upload error code: ' . $file['error']];
    }

    // Max 3MB
    $max_size = 3 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return ['success' => false, 'path' => null, 'error' => 'Image size exceeds maximum allowed limit (3MB).'];
    }

    // Check mime type
    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif'
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!array_key_exists($mime, $allowed_mimes)) {
        return ['success' => false, 'path' => null, 'error' => 'Invalid image format. Allowed formats: JPG, PNG, WEBP, GIF.'];
    }

    // Verify it is a valid image
    $img_info = @getimagesize($file['tmp_name']);
    if (!$img_info) {
        return ['success' => false, 'path' => null, 'error' => 'The uploaded file is not a valid image.'];
    }

    $ext = $allowed_mimes[$mime];
    $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR;
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }

    $filename = 'avatar_' . $prefix . '_' . (int)$user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target_file = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target_file)) {
        return ['success' => false, 'path' => null, 'error' => 'Failed to save uploaded image. Please check server permissions.'];
    }

    // Delete previous avatar file if safe
    if (!empty($old_avatar)) {
        delete_avatar_file($old_avatar);
    }

    $relative_path = 'uploads/avatars/' . $filename;
    return ['success' => true, 'path' => $relative_path, 'error' => null];
}

/**
 * Safely deletes an avatar file from uploads/avatars/
 */
function delete_avatar_file($relative_path) {
    if (empty($relative_path)) return;
    $clean_rel = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative_path);
    $base_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    $full_path = realpath($base_dir . $clean_rel);
    $avatars_dir = realpath($base_dir . 'uploads' . DIRECTORY_SEPARATOR . 'avatars');

    if ($full_path && $avatars_dir && strpos($full_path, $avatars_dir) === 0 && file_exists($full_path)) {
        @unlink($full_path);
    }
}
