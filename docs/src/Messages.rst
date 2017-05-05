Messages
========

This section describes the various messages that may be sent by each peer.

..  toctree::
    :local:


Messages emitted by the manager
-------------------------------

``COMMAND``
~~~~~~~~~~~

This message is sent to the worker whenever a command (a line of statements
given by the user) needs to be processed.

This message's payload consists of the actual statement(s) to process.

``SIGNAL``
~~~~~~~~~~

This message is sent to the worker whenever the manager receives a signal
and wishes to pass it on to the worker.

This message's payload consists of the signal number, represented as a string.
So for example, ``SIGTERM`` is represented by the following byte sequence:
``\\x31\\x35`` (ie. "15").

Messages emitted by workers
---------------------------

``READY``
~~~~~~~~~

This message is sent by the very first worker after it has been spawned
and indicates that it is fully initialized and ready to process incoming
commands.

This message's payload consists of the worker's full path and line number,
in the form ``full/path/to/Worker.php(line)``.

``START``
~~~~~~~~~

This message is sent by a worker before it starts executing a command.

This message's payload consists of the worker's
:abbr:`PID (Process IDentifier)`.

``END``
~~~~~~~

This message is sent by a worker after the command is was processing
finished executing.

This message has no associated payload.
