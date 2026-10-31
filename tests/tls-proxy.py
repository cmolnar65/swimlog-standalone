"""Disposable HTTPS transport for browser tests; never deploy this file."""
import http.client, http.server, os, ssl
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
server = http.server.ThreadingHTTPServer(('127.0.0.1', 443), Proxy)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain('/tmp/swimlog-cert.pem', '/tmp/swimlog-key.pem')
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
