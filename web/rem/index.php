<?php

$files = scandir(__DIR__);
$examples = [];
foreach ($files as $file) {
    if ($file === 'index.php' || $file === '.' || $file === '..' || $file === 'README.md') {
        continue;
    }
    $lines = file(__DIR__ . '/' . $file, FILE_SKIP_EMPTY_LINES);
    $title = trim($lines[1], '/ ');
    $examples[] = [
        'title' => $title,
        'file' => $file,
    ];
}

?>
<html>
    <head>
        <title>Remote Error Monitor Testing Examples</title>
    </head>
    <body>
        <h1>Examples list</h1>
        <ul>
            <?php foreach ($examples as $example): ?>
                <li>
                    <a href="<?= $example['file'] ?>"><?= $example['title'] ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </body>
</html>