<html>
    <body>
        <input type="text" id="messageField">
        <button type="submit" id="connect">Connect</button>
        <button type="submit" id="send">Send</button>
        <button type="submit" id="close">Close</button>

        <textarea id="chat" cols="50" rows="30" disabled></textarea>
    </body>
    <script>
        function start() {
            let socket = new WebSocket('ws://127.0.0.1:2048');
            alert(socket);
            const sendButton = document.getElementById('send'),
                closeButton = document.getElementById('close'),
                sendHandler = function (e) {
                    e.preventDefault();

                    socket.send(document.getElementById('messageField').value);
                },
                closeHandler = function (e) {
                    e.preventDefault();
                    removeEvents();
                    socket.close(1000);
                };

            socket.addEventListener('message', function (e) {
                let message = e.data;

                document.getElementById('chat').append(message + "\n");
            });

            socket.addEventListener('close', function (e) {
                console.log(e);
            });

            socket.addEventListener('error', function (e) {
                console.log(e);
            });

            sendButton.addEventListener('click', sendHandler);
            closeButton.addEventListener('click', closeHandler);

            function removeEvents()
            {
                document.getElementById('send').removeEventListener('click', sendHandler);
                document.getElementById('close').removeEventListener('click', closeHandler);
            }

            window.onbeforeunload = function () {
                socket.close();
            };
        }

        document.getElementById('connect').addEventListener('click', function (e) {
            e.preventDefault();

            start();
        });
    </script>
</html>
