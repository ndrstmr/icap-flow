#!/bin/sh
# Healthy only while /avscan finds the EICAR test file: a c-icap without a working engine answers 204 to everything.
# The test string is built in two pieces so that this file does not trigger virus scanners itself.
a='X5O!P%@AP[4\PZX54(P^)7CC)7}$'
b='EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
{
    printf 'RESPMOD icap://127.0.0.1/avscan ICAP/1.0\r\nHost: 127.0.0.1\r\nAllow: 204\r\nEncapsulated: res-hdr=0, res-body=39\r\n\r\n'
    printf 'HTTP/1.1 200 OK\r\nContent-Length: 68\r\n\r\n'
    printf '44\r\n%s%s\r\n0\r\n\r\n' "$a" "$b"
} | nc -w 3 127.0.0.1 1344 | grep -q 'X-Infection-Found:'
