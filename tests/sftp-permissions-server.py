"""Relay real loopback SFTP, emulating Windows chmod without touching ACLs.

Only used by the generated-key fixture. This is not a Windows/NTFS emulator.
The policy file selects noop, deny, or deny-writer for permission requests.
"""

import os
from pathlib import Path
import selectors
import struct
import subprocess
import sys


def packet(body):
    return struct.pack('>I', len(body)) + body


def status(request, code):
    return packet(bytes([101]) + request[1:5] + struct.pack('>III', code, 0, 0))


subsystem, fixture = sys.argv[1:]
fixture = Path(fixture)
server = subprocess.Popen([subsystem, '-u', '0077'], stdin=subprocess.PIPE,
                          stdout=subprocess.PIPE, bufsize=0)
selector = selectors.DefaultSelector()
selector.register(sys.stdin.buffer, selectors.EVENT_READ, 'client')
selector.register(server.stdout, selectors.EVENT_READ, 'server')
buffers = {'client': bytearray(), 'server': bytearray()}

try:
    while True:
        for key, _ in selector.select():
            data = os.read(key.fd, 262144)
            if not data:
                sys.exit(0)
            buffer = buffers[key.data]
            buffer.extend(data)
            while len(buffer) >= 4:
                length = struct.unpack('>I', buffer[:4])[0]
                if not 1 <= length <= 4194304:
                    raise RuntimeError('Invalid fixture SFTP packet length')
                if len(buffer) < length + 4:
                    break
                request = bytes(buffer[4:length + 4])
                del buffer[:length + 4]
                if key.data == 'client' and request[0] == 9:  # SSH_FXP_SETSTAT
                    path_length = struct.unpack('>I', request[5:9])[0]
                    path = request[9:9 + path_length].decode()
                    flags = struct.unpack('>I', request[9 + path_length:13 + path_length])[0]
                    if flags == 4:  # SSH_FILEXFER_ATTR_PERMISSIONS only
                        with (fixture / 'chmod-requests.log').open('a') as log:
                            log.write(path + '\n')
                        policy = (fixture / 'permission-policy').read_text().strip()
                        if policy in ('noop', 'deny') or (policy == 'deny-writer' and path.endswith('/writer')):
                            sys.stdout.buffer.write(status(request, 3 if policy != 'noop' else 0))
                            sys.stdout.buffer.flush()
                            continue
                if key.data == 'client' and request[0] == 18 and (fixture / 'deny-rename').exists():
                    sys.stdout.buffer.write(status(request, 4))
                    sys.stdout.buffer.flush()
                    continue
                if key.data == 'client' and request[0] == 15 and (fixture / 'deny-cleanup-rmdir').exists():
                    sys.stdout.buffer.write(status(request, 3))
                    sys.stdout.buffer.flush()
                    continue
                output = server.stdin if key.data == 'client' else sys.stdout.buffer
                wire = memoryview(packet(request))
                while wire:
                    wire = wire[output.write(wire):]
                output.flush()
finally:
    selector.close()
    server.stdin.close()
    server.terminate()
    server.wait(timeout=5)
