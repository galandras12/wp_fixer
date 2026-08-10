<?php
/**
 * Deliberately standalone - does NOT bootstrap WordPress.
 *
 * Used only by the "Szerver diagnosztika" tab's speed test to measure the
 * raw PHP + web server response time in isolation from WordPress's own
 * bootstrap overhead. Returns nothing sensitive - just a timestamp - and
 * makes no state changes, so it's safe to be publicly reachable.
 */
header( 'Content-Type: text/plain; charset=utf-8' );
header( 'Cache-Control: no-store' );
echo 'OK ' . microtime( true );
