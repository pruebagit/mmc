# -*- test-case-name: pulse2.cm.tests.server -*-
# -*- coding: utf-8; -*-
#
# (c) 2014 Mandriva, http://www.mandriva.com/
#
# This file is part of Pulse 2, http://pulse2.mandriva.org
#
# Pulse 2 is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# Pulse 2 is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Pulse 2; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
# MA 02110-1301, USA.

import logging

from OpenSSL.SSL import TLSv1_METHOD
from OpenSSL.SSL import VERIFY_PEER, VERIFY_FAIL_IF_NO_PEER_CERT

from twisted.internet import reactor

from twisted.internet.endpoints import SSL4ServerEndpoint
from twisted.internet.ssl import DefaultOpenSSLContextFactory
from twisted.internet.protocol import Protocol, Factory
from twisted.internet.address import IPv4Address

from pulse2.cm.collector import Collector


class GatheringServer(Protocol):
    """
    This service dispatches the communication between server and clients.

    The client machines sends periodically several informations
    to this server where gathered info is forwarded to the handler.
    The handler reference (a service processing received data)
    returns a result which is immediatelly sent to the client.
    """

    _handler = None
    _trigger = None

    @classmethod
    def set_handler(self, value):
        if hasattr(value, "queue_and_process"):
            self._handler = value
        else:
            raise AttributeError, "Handler instance must have 'queue_and_process' attribute"

    @classmethod
    def set_trigger(self, value):
        if hasattr(value, "fire"):
            self._trigger = value
        else:
            raise AttributeError, "Handler instance must have 'fire' attribute"



    def dataReceived(self, data):
        """
        Method invoked when any data received.

        @param data: received data
        @type data: str
        """
        address = self.transport.getPeer()

        if isinstance(address, IPv4Address):
            ip = address.host
        else:
            ip = None

        d = self._handler.queue_and_process(ip, data)
        d.addCallback(self.send_response)
        d.addErrback(self._response_failed)


        logging.getLogger().debug("data received: %s from %s" % (str(data), ip))
        try:
            tr_result = self._trigger.fire()
            #@tr_result.addCallback
            #def res(result):
            #    print "trigger result: %s" % (str(result))
        except Exception, e:
            logging.getLogger().warn("\033[31mtrigger firing fail: %s\033[0m" % str(e))



    def send_response(self, response):
        logging.getLogger().debug("\033[35mresponse to client: %s\033[0m" % str(response))
        self.transport.write(response)

    def _response_failed(self, failure):
        logging.getLogger().warn("\033[31mresponse failed: %s\033[0m" % str(failure))


class GatheringFactory(Factory):
    protocol = GatheringServer

class Server(object):
    def __init__(self, key, crt, pem, ssl_method):
        """
        @param key: private key filename
        @type key: str

        @param crt: certificate filename
        @type crt: str

        @param pem: PEM file path
        @type pem: str

        @param ssl_method: SSL method
        @type: str
        """
        if ssl_method == "TLSv1_METHOD":
            from OpenSSL.SSL import TLSv1_METHOD
            ssl_method = TLSv1_METHOD
        elif ssl_method == "SSLv23_METHOD":
            from OpenSSL.SSL import SSLv23_METHOD
            ssl_method = SSLv23_METHOD
        elif ssl_method == "SSLv2_METHOD":
            from OpenSSL.SSL import SSLv2_METHOD
            ssl_method = SSLv2_METHOD
        elif ssl_method == "SSLv3_METHOD":
            from OpenSSL.SSL import SSLv3_METHOD
            ssl_method = SSLv3_METHOD
        else:
            raise TypeError


        self.ctx_factory = DefaultOpenSSLContextFactory(key,
                                                        crt,
                                                        ssl_method
                                                        )

        mode = VERIFY_PEER | VERIFY_FAIL_IF_NO_PEER_CERT

        ctx = self.ctx_factory.getContext()
        ctx.set_verify(mode, self._verify_callback)
        ctx.load_verify_locations(pem)

    def _verify_callback(self, connection, x509, errnum, errdepth, ok):
        #print "%s / %s / %s / %s / %s" % (connection, x509, errnum, errdepth, ok)
        #print "error code: %d" % errnum
        if not ok:
            logging.getLogger().warn('invalid cert from subject:', x509.get_subject())
            return False
        else:
            return True


    def start(self, handler, trigger):

        self.factory = GatheringFactory()

        self.factory.protocol.set_handler(handler)
        self.factory.protocol.set_trigger(trigger)

        endpoint = SSL4ServerEndpoint(reactor, 8088, self.ctx_factory)
        d = endpoint.listen(self.factory)

        @d.addCallback
        def cb(reason):
            logging.getLogger().info("endpoint start: %s" % str(reason))
        @d.addErrback
        def eb(failure):
            logging.getLogger().warn("endpoint start failed: %s" % str(failure))


        return d

# for TLS:
# openssl genrsa -out root.key 4096
# openssl req -x509 -new -nodes -key root.key -days 1024 -out root.pem
# openssl genrsa -out server.key 4096
# openssl genrsa -out client.key 4096
# openssl req -new -key server.key -out server.csr
# openssl req -new -key client.key -out client.csr
# openssl x509 -req -in server.csr -CA root.pem -CAkey root.key -CAcreateserial -out server.crt -days 1023
# openssl x509 -req -in client.csr -CA root.pem -CAkey root.key -CAcreateserial -out client.crt -days 1023

def test_with_trigger(server):
    import time
    from random import randrange
    from pulse2.cm.collector import Collector, Sessions
    from pulse2.cm.trigger import Trigger

    from twisted.test.proto_helpers import StringTransport
    from twisted.internet import reactor
    from twisted.internet.task import deferLater


    collector = Collector()

    def process_responses(_collector):
        #print _collector.queue
        while True:
            result = _collector.get()

            if not result:
                break

            uid, ip, request = result

            delay = randrange(1, 20)


            #d = deferLater(reactor, delay, _collector.release, uid, "ok")
            # send the request back (echo test)
            d = deferLater(reactor, delay, _collector.release, uid, request)
            @d.addCallback
            def cb(reason):
                pass
                #print "\033[33mrelease request: %s (delay=%d)\033[0m" % (request, delay)
            @d.addErrback
            def eb(reason):
                pass
                #print "\033[31mrelease request failed: %s \033[0m" % (reason)


            #_collector.release(uid, "ok")

    try:
        trigger = Trigger(process_responses, collector)
    except Exception, e:
        logging.getLogger().warn("\033[31mtrigger instance failed: %s\033[0m" % str(e))

    server.start(collector, trigger)

#    factory = GatheringFactory()
#    factory.protocol.set_handler(collector)
#    factory.protocol.set_trigger(trigger)
#
#    protocol = factory.buildProtocol(("127.0.0.1", 0))
#    transport = StringTransport()
#    protocol.makeConnection(transport)

#    prev_stamp = 0
#    for kk in xrange(3):
#        for i in xrange(5):
#
#            request = "hello_%d" % randrange(0, 99999)
#            protocol.dataReceived(request)
#
#            lag = time.time() - prev_stamp
#
#            print "\033[32mrelease request: %d \033[0m" % (lag)
#            prev_stamp = time.time()
#        time.sleep(5)
#
#    print len(collector.queue)


if __name__ == "__main__":

    key = "/root/dev/smart_agent/tls/server.key"
    crt = "/root/dev/smart_agent/tls/server.crt"
    pem = "/root/dev/smart_agent/tls/root.pem"


    #collector = Collector()

    s = Server(key, crt, pem, TLSv1_METHOD)
    #s.start(collector)
    test_with_trigger(s)
    reactor.run()



