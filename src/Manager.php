<?php

namespace fpoirotte\push;

class Manager extends Protocol
{
    const OP_COMMAND = "\x00";
    const OP_SIGNAL  = "\x01";

    static protected $workerCls = '\\fpoirotte\\push\\Worker';

    protected $workerPid;
    protected $statements;
    protected $working;
    protected $evalLocation;

    public function __construct()
    {
        parent::__construct(static::$workerCls);
        $this->workerPid    = -1;
        $this->statements   = array();
        $this->working      = false;
        $this->evalLocation = '';
    }

    public function isWorking()
    {
        return $this->working;
    }

    public function getEvalLocation()
    {
        return $this->evalLocation;
    }

    protected function replaceSpecialStream($streamName, $new)
    {
        $special = array('STDIN', 'STDOUT', 'STDERR');
        $tmp = eio_dup2($new, array_search($streamName, $special, true));
        if ($tmp === false) {
            throw new \RuntimeException('Could not duplicate stream');
        }

        if (!eio_event_loop()) {
            throw new \RuntimeException('Could not run event loop');
        }
    }

    public function prepare(&$stdout, &$stderr, &$control)
    {
        $pipes      = array();
        $managerPid = getmypid();

        /* Open several socket pairs for IPC:
         * 1 = worker's STDOUT (unidirectional)
         * 2 = worker's STDERR (unidirectional)
         * 3 = control socket (bidirectional)
         */
        for ($i = 1; $i < 4; $i++) {
            if (!$pipes[$i] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)) {
                while ($i > 1) {
                    fclose($pipes[$i-1][0]);
                    fclose($pipes[$i-1][1]);
                    $i--;
                }
                throw new \RuntimeException('Failed to create socket pairs');
            }
        }

        $pid = pcntl_fork();
        if ($pid < 0) {
            for ($i = 1; $i < 4; $i++) {
                fclose($pipes[$i][0]);
                fclose($pipes[$i][1]);
            }
            throw new \RuntimeException('Failed to fork child process');
        }

        if ($pid > 0) {
            $this->workerPid    = $pid;
            $this->statements   = array();

            fclose($pipes[1][1]);
            fclose($pipes[2][1]);
            fclose($pipes[3][1]);

            $stdout         = $pipes[1][0];
            $stderr         = $pipes[2][0];
            $control        = $pipes[3][0];
            $this->socket   = $pipes[3][0];

            // Using stream_select() is the preferred way
            // to deal with I/O events on these streams.
            stream_set_blocking($stdout, 0);
            stream_set_blocking($stderr, 0);
        } else {
            $title = "push (worker for process $managerPid)";
            if (function_exists('cli_set_process_title')) {
                cli_set_process_title($title);
            } elseif (function_exists('setproctitle')) {
                setproctitle($title);
            }

            // Close the reading end of the streams.
            fclose($pipes[1][0]);
            fclose($pipes[2][0]);
            fclose($pipes[3][0]);

            // Overwrite the special constants STDIN, STDOUT & STDERR
            // with new streams so that the worker's output/errors can
            // by intercepted.
            $this->replaceSpecialStream("STDIN", fopen('/dev/null', 'rb'));
            $this->replaceSpecialStream("STDOUT", $pipes[1][1]);
            $this->replaceSpecialStream("STDERR", $pipes[2][1]);

            $cls    = static::$workerCls;
            $worker = new $cls($pipes[3][1]);
            $worker->run();
        }
    }

    protected function sendWork()
    {
        if (!count($this->statements) || $this->working) {
            return;
        }

        $this->working = true;
        $this->send(self::OP_COMMAND, array_shift($this->statements));
    }

    public function sendCommands($data)
    {
        // Push the new statement and reindex the queue.
        array_push($this->statements, $data);
        $this->statements = array_values($this->statements);
        $this->sendWork();
    }

    public function sendSignal($signo)
    {
        if (!is_int($signo)) {
            if (!strncmp($signo, 'SIG', 3) && defined($signo)) {
                $signo = constant($signo);
            } else {
                throw new \RuntimeException();
            }
        }

        switch ($signo) {
            case SIGCHLD:
                $child = pcntl_waitpid($this->workerPid, $status, WNOHANG);
                if ($child > 0) {
                    if (pcntl_wifexited($child)) {
                        exit(pcntl_wexitstatus($child));
                    }
                }
                return; // The worker must not receive its own signal.
        }

        $this->send(self::OP_SIGNAL, (string) $signo);
    }

    protected function handle_READY($data)
    {
        // $data contains the file & line where PHP evaluates the code.
        // We use this to filter out the output from errors/exceptions.
        $this->evalLocation = $data;
    }

    protected function handle_START($data)
    {
        $this->workerPid = (int) $data;
    }

    protected function handle_END()
    {
        $this->working = false;
        $this->sendWork();
    }
}
