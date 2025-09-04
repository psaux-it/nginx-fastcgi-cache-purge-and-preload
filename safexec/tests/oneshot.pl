#!/usr/bin/env perl
# Respond to socat
use strict; use warnings;
local $/ = "\r\n\r\n";
my $req = <STDIN> // "";
open my $f, '>', '/tmp/req1' or die $!;
print {$f} $req; close $f;
print "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
