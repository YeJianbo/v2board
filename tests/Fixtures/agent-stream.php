<?php
header('Content-Type: text/event-stream');
if (isset($_GET['late'])) sleep(61);
echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'stream-ok']]]]) . "\n\n";
flush();
if (isset($_GET['slow'])) sleep(3);
echo "data: [DONE]\n\n";
flush();
