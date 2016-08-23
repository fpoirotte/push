<?php

namespace fpoirotte\push;

class Worker extends Protocol
{
    const OP_START = "\x00";
    const OP_END   = "\x01";
    const OP_READY = "\x02";

    protected $child;
    protected $scope;
    protected $data;
    protected $result;

    public function __construct($socket)
    {
        parent::__construct('\\fpoirotte\\push\\Manager');
        $this->socket   = $socket;
        $this->child    = null;
        $this->scope    = array();
        $this->data     = null;
        $this->result   = null;
    }

    public function run()
    {
        list($file, $line) = $this->evaluate(true);
        $this->send(self::OP_READY, "$file($line)");

        while (true) {
            $this->runOnce();
        }
    }

    protected function cancel()
    {
        if ($this->child === null) {
            return;
        }

        echo "Cancelling...\n";
        posix_kill($this->child, SIGKILL);
        pcntl_signal_dispatch();
    }

    protected function handle_COMMAND($data)
    {
        $pid = getmypid();
        $this->child = pcntl_fork();
        if ($this->child < 0) {
            throw new \RuntimeException('Could not run command');
        }

        if ($this->child > 0) {
            pcntl_signal(SIGINT, array($this, 'cancel'), true);
            $child = pcntl_waitpid(-1, $status);
            if ($child > 0) {
                if (pcntl_wifexited($child) && pcntl_wexitstatus($child) === 0) {
                    exit(0);
                }
                $this->child = null;
                $this->send(self::OP_END);
            }
        } else {
            pcntl_signal(SIGINT, SIG_DFL, true);
            $this->send(self::OP_START, (string) getmypid());
            $this->data = $data;
            ini_set('display_errors', '0');
            $this->evaluate();
            ini_set('display_errors', '1');
            $this->outputResult();
            posix_kill($pid, SIGKILL);
            pcntl_signal_dispatch();
            $this->send(self::OP_END);
        }
    }

    protected function evaluate($magic=false)
    {
        if ($magic) {
            // Return this file's name & the line where eval() can be found.
            return array(__FILE__, __LINE__ + 4);
        }
        unset($magic);
        extract($this->scope, EXTR_OVERWRITE | EXTR_PREFIX_SAME, '_');
        $this->result = eval('return ' . $this->data);
        $this->scope = get_defined_vars();
        return $this->result;
    }

    protected function outputResult()
    {
        if ($this->result === null) {
            return;
        }

        fwrite(STDOUT, var_export($this->result, true));
    }

    protected function handle_SIGNAL($data)
    {
        $signo = (int) $data;
        if ($signo === 0) {
            throw new \RuntimeException();
        }

        posix_kill(getmypid(), $signo);
        pcntl_signal_dispatch();
    }
}
