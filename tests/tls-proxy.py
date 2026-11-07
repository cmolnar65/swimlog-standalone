"""Disposable HTTPS transport for browser tests; never deploy this file."""
import http.client, http.server, os, ssl, socket, select, threading
if os.environ.get('SWIMLOG_SYNTHETIC_TEST') != '1':
    raise SystemExit('Disposable test flag required')
class Proxy(http.server.BaseHTTPRequestHandler):
    def forward(self):
        if self.headers.get('Host') not in ('wordpress.example.test', 'swimlog.example.test'):
            self.send_error(400); return
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        connection = http.client.HTTPConnection('127.0.0.1', 8099, timeout=30)
        headers = dict(self.headers)
        headers['Connection'] = 'close'
        connection.request(self.command, self.path, body, headers)
        response = connection.getresponse(); data = response.read()
        self.send_response(response.status)
        for key, value in response.getheaders():
            if key.lower() not in ('connection','transfer-encoding','content-length','server','date'):
                self.send_header(key, value)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers(); self.wfile.write(data); connection.close()
    do_GET = forward
    do_POST = forward
    def log_message(self, *args): pass
server = http.server.ThreadingHTTPServer(('127.0.0.1', 8443), Proxy)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain('/tmp/swimlog-cert.pem', '/tmp/swimlog-key.pem')
server.socket = context.wrap_socket(server.socket, server_side=True)
threading.Thread(target=server.serve_forever, daemon=True).start()
class Tunnel(http.server.BaseHTTPRequestHandler):
    def do_CONNECT(self):
        if self.path not in ('wordpress.example.test:443', 'swimlog.example.test:443'):
            self.send_error(403); return
        with socket.create_connection(('127.0.0.1', 8443), timeout=15) as upstream:
            self.send_response(200); self.end_headers()
            upstream.settimeout(None); self.connection.settimeout(None)
            peers = (self.connection, upstream)
            while True:
                ready, _, _ = select.select(peers, [], [], 30)
                if not ready: return
                for source in ready:
                    data = source.recv(65536)
                    if not data: return
                    (upstream if source is self.connection else self.connection).sendall(data)
    def log_message(self, *args): pass
http.server.ThreadingHTTPServer(('127.0.0.1', 8088), Tunnel).serve_forever()
