<?php

declare(strict_types=1);

namespace Wik\Lexer\Debug;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware that injects Lex debug data into HTML responses.
 *
 * Must be registered AFTER LexDebugger is constructed (so hooks are wired)
 * and AFTER the Lex render happens in the handler chain.
 *
 * Injects:
 *  - <script id="__lex_debug__"> JSON payload before </body>
 *  - X-Lex-Debug, X-Lex-Render-Time, X-Lex-Cache-Hits response headers
 *
 * Only activates when the response Content-Type contains text/html.
 *
 * Requires: psr/http-server-middleware (composer require psr/http-server-middleware)
 */
final class DebugMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        DebugPayload::reset();

        $response = $handler->handle($request);

        // Only inject into HTML responses
        if (!str_contains($response->getHeaderLine('Content-Type'), 'text/html')) {
            return $response;
        }

        $payload = DebugPayload::getInstance();
        $body    = (string) $response->getBody();
        $script  = "\n<script id=\"__lex_debug__\" type=\"application/json\">"
                 . $payload->toJson()
                 . '</script>';

        $body = stripos($body, '</body>') !== false
            ? str_ireplace('</body>', $script . '</body>', $body)
            : $body . $script;

        $stream = $response->getBody();
        $stream->rewind();
        $stream->write($body);

        return $response
            ->withBody($stream)
            ->withHeader('X-Lex-Debug',       '1')
            ->withHeader('X-Lex-Render-Time', (string) $payload->getRenderTime())
            ->withHeader('X-Lex-Cache-Hits',  (string) $payload->getCacheHitCount())
            ->withHeader('X-Lex-Cache-Miss',  (string) $payload->getCacheMissCount())
            ->withHeader('X-Lex-Template',    (string) ($payload->getRootTemplate() ?? ''));
    }
}
