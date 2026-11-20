"""Loopback PostgreSQL proxy for the small-payload RTT sensitivity control."""
import asyncio
import sys


async def main():
    async def connect(client_reader, client_writer):
        server_reader, server_writer = await asyncio.open_connection("127.0.0.1", int(sys.argv[1]))

        async def relay(reader, writer, delay):
            try:
                while data := await reader.read(65536):
                    if delay:
                        await asyncio.sleep(delay)
                    writer.write(data)
                    await writer.drain()
            except (ConnectionResetError, BrokenPipeError):
                pass
            finally:
                writer.close()

        await asyncio.gather(relay(client_reader, server_writer, 0), relay(server_reader, client_writer, 0.0014))

    server = await asyncio.start_server(connect, "127.0.0.1", 0, backlog=128)
    print(server.sockets[0].getsockname()[1], flush=True)
    async with server:
        await server.serve_forever()


asyncio.run(main())
