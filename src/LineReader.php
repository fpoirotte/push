<?php

namespace fpoirotte\push;

class LineReader
{
    protected $manager;
    protected $stop;

    const PS1 = "\001\033[1;32m\002>>>\001\033[0m\002 ";

    public function __construct(\fpoirotte\push\Manager $manager)
    {
        $this->manager  = $manager;
        $this->stop     = false;
        $this->prompt   = static::PS1;
    }

    protected function filter($data)
    {
        if (substr($data, -1) === "\n") {
            $data = (string) substr($data, 0, -1);
        }

        $output     = array();
        $lines      = explode("\n", $data);
        $pattern    = $this->manager->getEvalLocation();
        $pattern    = " in $pattern : eval()'d code on line ";
        $pLen       = strlen($pattern);
        foreach ($lines as $line) {
            $pos = strpos($line, $pattern);
            if ($pos !== false) {
                $matchEnd = strspn(substr($line, $pos + $pLen), '1234567890');
                if ($pos + $pLen + $matchEnd === strlen($line)) {
                    $output[] = (string) substr($line, 0, $pos);
                } else {
                    $output[] = $line;
                }
            } else {
                $output[] = $line;
            }
        }
        return implode("\n", $output) . "\n";
    }

    protected function signalHandler($signo)
    {
        switch ($signo) {
            case SIGINT:
                // This is silly, but required to provide feedback.
                echo "^C\n";
                if (!$this->manager->isWorking()) {
                    return;
                }
                break;
        }

        $this->manager->sendSignal($signo);
    }

    protected function lineHandler($line)
    {
        if ($line === "") {
            return;
        }

        readline_callback_handler_remove();

        if ($line === null || $line == "exit") {
            if ($line === null) {
                echo "^D\n";
            }

            // Explicit call to "exit" or Ctrl+D
            $this->stop = true;
            return;
        }

        readline_add_history($line);
        $this->manager->sendCommands($line);
        $this->prompt = '';
    }

    public function run()
    {
        declare(ticks=1);

        $stdout = $stderr = $control = null;
        $this->manager->prepare($stdout, $stderr, $control);

        $signals = array(
            'SIGHUP',
            'SIGINT',
            'SIGQUIT',
            'SIGILL',
            'SIGTRAP',
            'SIGABRT',
            'SIGIOT',
            'SIGBUS',
            'SIGFPE',
            //'SIGKILL', // SIGKILL cannot be intercepted.
            'SIGUSR1',
            'SIGSEGV',
            'SIGUSR2',
            'SIGPIPE',
            'SIGALRM',
            'SIGTERM',
            'SIGSTKFLT',
            'SIGCLD',
            'SIGCHLD',
            //'SIGCONT', // Signals that suspend/resume execution
            //'SIGSTOP', // are left intact. We want the manager
            //'SIGTSTP', // to suspend its execution, not its children.
            'SIGTTIN',
            'SIGTTOU',
            'SIGURG',
            'SIGXCPU',
            'SIGXFSZ',
            'SIGVTALRM',
            'SIGPROF',
            'SIGWINCH',
            'SIGPOLL',
            'SIGIO',
            'SIGPWR',
            'SIGSYS',
            'SIGBABY',
        );

        foreach ($signals as $signal) {
            if (!defined($signal)) {
                continue;
            }

            if (!pcntl_signal(constant($signal), array($this, 'signalHandler'), true)) {
                throw new \RuntimeException('Unable to set up signal handler for ' . $signal);
            }
        }

        while (true) {
            if ($this->stop) {
                readline_write_history();
                echo "Exiting...\n";
                break;
            }

            $r = array(STDIN, $stdout, $stderr, $control);
            $w = $e = array();

            $nb = @stream_select($r, $w, $e, null);
            pcntl_signal_dispatch();

            if ($nb === false) {
                continue;
            }

            if (in_array(STDIN, $r)) {
                if ($this->manager->isWorking()) {
                    $tmp = fgetc(STDIN);
                } else {
                    readline_callback_read_char();
                }
            }

            if (in_array($stdout, $r)) {
                $read = fread($stdout, 8192);
                if ($read !== false) {
                    fwrite(STDOUT, $this->filter($read));
                }
            }

            if (in_array($stderr, $r)) {
                $read = fread($stderr, 8192);
                if ($read !== false) {
                    fwrite(STDERR, $this->filter($read));
                }
            }

            if (in_array($control, $r)) {
                try {
                    $this->manager->runOnce();
                } catch (\RuntimeException $e) {
                    throw $e;
                    break;
                }

                if (!$this->manager->isWorking()) {
                    $this->prompt = static::PS1;
                }

                if (!readline_callback_handler_install($this->prompt, array($this, 'lineHandler'))) {
                    throw new \RuntimeException();
                }
            }
        }
    }
}
