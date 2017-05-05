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
        // Prepare the environment.
        list($file, $line) = $this->evaluate(true);

        // We are now ready to process commands, let's notify the manager!
        $this->send(self::OP_READY, "$file($line)");

        // We keep processing incoming commands.
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

        if ($this->child == 0) {
            // We are now executing in a new worker. \o/

            // Restore the default handler for the SIGINT signal.
            pcntl_signal(SIGINT, SIG_DFL, true);

            // Notify the manager that we are about to process the command.
            $this->send(self::OP_START, (string) getmypid());

            // Execute the command and output the results.
            $this->data = $data;
            ini_set('display_errors', '0');
            $this->evaluate();
            ini_set('display_errors', '1');
            $this->outputResult();

            /* Kill the previous worker, because it is now obsolete
             * (the new worker has a more up-to-date state, which
             * includes any potential side effects from the last command).
             * Finally, we notify the manager that the command's execution
             * has finished (so that it may send new commands our way).
             *
             * Note :
             *
             * This section of the code is never executed if the command
             * raised a fatal error/exception.
             * In this case, the previous worker will handle it.
            */
            posix_kill($pid, SIGKILL);
            pcntl_signal_dispatch();
            $this->send(self::OP_END);
        } else {
            // We're executing in the old worker.

            // Set up a signal handler so that we may interrupt the running
            // command if necessary.
            pcntl_signal(SIGINT, array($this, 'cancel'), true);

            // Wait for the new worker to terminate.
            // Normally, this will never happen because the new worker
            // will kill us right after it's done doing whatever it was doing.
            $child = pcntl_waitpid(-1, $status);
            if ($child > 0) {
                // The new worker exited normally (eg. executed "exit 0;").
                // In that case, we also exit normally.
                if (pcntl_wifexited($child) && pcntl_wexitstatus($child) === 0) {
                    exit(0);
                }

                // Otherwise, it means the command triggered a fatal error
                // and we have the latest state.
                // Notify the manager that we are ready to accept new commands.
                $this->child = null;
                $this->send(self::OP_END);
            }
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

        // Just in case we received something that is not a valid signal.
        if ($signo === 0) {
            throw new \RuntimeException();
        }

        posix_kill(getmypid(), $signo);
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
