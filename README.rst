push
====

Introduction
------------

push stands for "PHP's Usable SHell" and is meant to be a user-friendly
shell written in PHP. The shell itself accepts PHP code as its commands.
push's architecture is based on the excellent work done by d11wtq on the
Boris REPL project.

The main difference between Boris and push is that we try to make it
very easy to embed push in your own application.
For example, this means that the standard output/error streams from
the shell's worker process can be captured. It also means the default
classes used for input/output can be replaced with your own if necessary.

Installation
------------

First, make sure `Composer`_ is installed. If not, follow
the `installation instructions <https://getcomposer.org/download/>`_
on their website.

Then, make sure the following extensions are installed and enabled
for your PHP installation:

* Sockets
* POSIX
* pcntl
* `eio <http://pecl.php.net/eio>`_

If necessary, install the missing extensions.

Finally, use `Composer`_ to install push:

..  sourcecode:: bash

    me@localhost:~/$ php /path/to/composer.phar require fpoirotte/push

Usage
-----

Just run ``vendor/bin/push`` and you are ready to go!

License
-------

push is licensed under the MIT license.

..  _`Composer`:
    https://getcomposer.org/

.. vim: ts=4 et
