<?php

namespace fpoirotte\push;

abstract class Protocol
{
    protected $socket;
    protected $opcodes;

    public function __construct($peer)
    {
        // Create a mapping of all the opcodes defined by the remote peer
        // to each operation's name.
        // This is used later on to call the appropriate handler for an opcode.
        $opcodes = array();
        $reflect = new \ReflectionClass($peer);
        foreach ($reflect->getConstants() as $name => $value) {
            if (!strncmp($name, 'OP_', 3)) {
                $opcodes[ord($value)] = (string) substr($name, 3);
            }
        }
        $this->opcodes  = $opcodes;
    }

    protected function send($op, $data='')
    {
        // Prevent overflows.
        if (strlen($data) > 65535) {
            throw new \RuntimeException();
        }

        // Encode the operation & payload,
        // and ensure the complete message is sent to the remote peer.
        $data       = $op . pack('n', strlen($data)) . $data;
        $len        = strlen($data);
        $written    = 0;
        while ($written < $len) {
            $wrote = @fwrite($this->socket, substr($data, $written));
            if ($wrote === false) {
                throw \RuntimeException();
            }

            $written += $wrote;
        }
    }

    protected function receive()
    {
        // Read the opcode and payload size.
        $res = '';
        while (($len = strlen($res)) != 3) {
            $read = @fread($this->socket, 3 - $len);
            if (feof($this->socket)) {
                throw new \RuntimeException();
            }
            if ($read !== false) {
                $res .= $read;
            }
        }
        list($op, $dataLen) = array_values(unpack('cop/ndata', $res));

        // Read the actual payload.
        $data = '';
        while (($len = strlen($data)) != $dataLen) {
            $read = @fread($this->socket, $dataLen - $len);
            if (feof($this->socket)) {
                throw new \RuntimeException();
            }
            if ($read !== false) {
                $data .= $read;
            }
        }

        return array($op, $data);
    }

    public function runOnce()
    {
        // Read an operation that needs to be processed.
        list($op, $data) = $this->receive();

        // Make sure the opcode is recognized.
        if (!isset($this->opcodes[$op])) {
            throw new \RuntimeException();
        }

        // Call the handler for that operation (if one has been defined).
        $handler = 'handle_' . $this->opcodes[$op];
        if (method_exists($this, $handler)) {
            return call_user_func(array($this, $handler), $data);
        }
    }
}
