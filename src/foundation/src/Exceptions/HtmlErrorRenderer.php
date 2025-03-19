<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions;

use Throwable;

class HtmlErrorRenderer
{
    public function render(Throwable $throwable, bool $debug = false): string
    {
        $title = $debug ? get_class($throwable) : 'Whoops! Something went wrong.';
        $message = $debug ? $throwable->getMessage() : 'An error occurred. Please try again later.';

        $html = $this->getHtmlTemplate($title, $message);

        if ($debug) {
            $html = str_replace('<!-- DEBUG_INFO -->', $this->getDebugInfo($throwable), $html);
        }

        return $html;
    }

    protected function getHtmlTemplate(string $title, string $message): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f8fafc;
            color: #636b6f;
            height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - 40px);
        }
        .title {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 20px;
            text-align: center;
        }
        .message {
            font-size: 18px;
            text-align: center;
            max-width: 1000px;
            width: 100%;
        }
        .debug-info {
            margin-top: 40px;
            background-color: #fff;
            border: 1px solid #e3e3e3;
            border-radius: 4px;
            padding: 20px;
            max-width: 1200px;
            width: 100%;
            overflow-x: auto;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        @media (max-width: 768px) {
            .title {
                font-size: 28px;
            }
            .message {
                font-size: 16px;
            }
            .debug-info {
                font-size: 14px;
            }
        }
        @media (max-width: 480px) {
            .title {
                font-size: 24px;
            }
            .message {
                font-size: 14px;
            }
            .debug-info {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="title">{$title}</div>
        <div class="message">{$message}</div>
        <!-- DEBUG_INFO -->
    </div>
</body>
</html>
HTML;
    }

    protected function getDebugInfo(Throwable $throwable): string
    {
        $trace = $throwable->getTraceAsString();
        $file = $throwable->getFile();
        $line = $throwable->getLine();

        return <<<HTML
<div class="debug-info">
    <h3>Debug Information:</h3>
    <p><strong>File:</strong> {$file}</p>
    <p><strong>Line:</strong> {$line}</p>
    <h4>Stack Trace:</h4>
    <pre>{$trace}</pre>
</div>
HTML;
    }
}
