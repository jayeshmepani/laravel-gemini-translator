<?php

// Find the project's root artisan file
$artisanPath = __DIR__ . '/../../../../artisan';

if (file_exists($artisanPath)) {
    echo "Running php artisan gemini:install...\n";
    // Run the command and display its output
    passthru('php ' . escapeshellarg($artisanPath) . ' gemini:install');
    echo "gemini:install command finished.\n\n";
}

// Display .env instructions
$blue = "\033[94m";
$green = "\033[92m";
$reset = "\033[0m";

echo $blue . "------------------------------------------------------------------\n";
echo " Laravel Gemini Translator - Post-Install Setup\n";
echo "------------------------------------------------------------------\n" . $reset;
echo "✅ Please add the following variables to your " . $green . ".env" . $reset . " file:\n\n";
echo $green . "GEMINI_API_KEY" . $reset . "=YOUR_ACTUAL_API_KEY\n";
echo $green . "GEMINI_REQUEST_TIMEOUT" . $reset . "=600\n\n";
echo "Get your API key from Google AI Studio.\n";
echo "------------------------------------------------------------------\n\n";