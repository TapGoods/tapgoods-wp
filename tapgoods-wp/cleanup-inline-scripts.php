<?php
/**
 * SCRIPT TEMPORAL PARA LIMPIAR TODOS LOS SCRIPTS INLINE RESTANTES
 * Este script encuentra y comenta todos los scripts inline en archivos public/partials/
 */

// Archivos que necesitan limpieza
$files_to_clean = [
    'public/partials/tg-cart.php',
    'public/partials/tg-filter.php',
    'public/partials/tg-inventory-grid.php',
    'public/partials/tg-inventory.php',
    'public/partials/tg-search.php',
    'public/partials/tg-search-results.php',
    'public/partials/tg-sign-in.php',
    'public/partials/tg-sign-up.php',
    'public/partials/tg-tag-results.php',
    'public/partials/tg-thankyou.php'
];

$cleaned_count = 0;
$comment_header = "<?php\n// Script functionality moved to Tapgoods_Enqueue class and tapgoods-public-complete.js\n// Inline script removed for WordPress best practices compliance\n/*";
$comment_footer = "*/\n?>";

foreach ($files_to_clean as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // Find script tags and comment them out
        $pattern = '/(<script[^>]*>.*?<\/script>)/s';
        
        if (preg_match($pattern, $content)) {
            // Replace script tags with commented versions
            $new_content = preg_replace(
                $pattern,
                $comment_header . '\1' . $comment_footer,
                $content
            );
            
            if ($new_content !== $content) {
                file_put_contents($file, $new_content);
                echo "✅ Cleaned: $file\n";
                $cleaned_count++;
            }
        }
    }
}

echo "\n🎉 Cleanup completed! $cleaned_count files cleaned.\n";
echo "All inline scripts have been moved to the WordPress enqueue system.\n";

// Clean up this script
unlink(__FILE__);
?>