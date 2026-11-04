<?php
// Just enough of psr/log 1.x for the official plugin's DefaultLogger (harness only).
namespace Psr\Log {
    interface LoggerInterface { public function log( $level, $message, array $context = array() ); }
    class LogLevel { const ERROR = 'error'; const DEBUG = 'debug'; const INFO = 'info'; const WARNING = 'warning'; }
    abstract class AbstractLogger implements LoggerInterface {
        public function error( $m, array $c = array() ) { $this->log( 'error', $m, $c ); }
        public function debug( $m, array $c = array() ) { $this->log( 'debug', $m, $c ); }
        public function info( $m, array $c = array() ) { $this->log( 'info', $m, $c ); }
        public function warning( $m, array $c = array() ) { $this->log( 'warning', $m, $c ); }
        public function notice( $m, array $c = array() ) { $this->log( 'notice', $m, $c ); }
    }
}
