push
====

Introduction
------------

push stands for "PHP's Usable SHell" and is meant to be user-friendly
shell written in PHP.
Its architecture is based on the excellent work done by d11wtq on the
Boris REPL project.

The main difference between Boris and push is that we try to make it
very easy to embed push in your own application.
For example, this means that the standard output/error streams from
the shell's worker process can be captured. It also means the default
classes used for input/output can be replaced with your own if necessary.

License
-------

push is licensed under the MIT license.
