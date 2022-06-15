<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_dataflows\local\execution;

use Symfony\Component\Yaml\Yaml;
use tool_dataflows\dataflow;
use tool_dataflows\local\step\flow_cap;

/**
 * Executes a dataflow.
 *
 * Once an engine has been created, it can be executed in one action, or stepped through.
 * Call execute() to run the engine completely through, or execute_step() to execute one
 * step.
 * Regardless of the method of execution, you will need to check for an aborted status.
 *
 * @package   tool_dataflows
 * @author    Jason den Dulk <jasondendulk@catalyst-au.net>
 * @copyright 2022, Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {

    /**
     * Defines the execution status used by the engine and the engine steps.
     */
    /** @var int Just created. */
    const STATUS_NEW = 0;
    /** @var int Initialised. Ready to run. */
    const STATUS_INITIALISED = 1;
    /** @var int Connector cannot proceed, waiting on upstreams. */
    const STATUS_BLOCKED = 2;
    /** @var int Flow cannot proceed, waiting on upstreams. */
    const STATUS_WAITING = 3;
    /** @var int Connector is currently processing. */
    const STATUS_PROCESSING = 4;
    /** @var int Flow is currently active */
    const STATUS_FLOWING = 5;
    /** @var int Step has finished activity. Downstreams can proceed. */
    const STATUS_FINISHED = 6;
    /** @var int Step has been cancelled. Downstreams may also be cancelled. */
    const STATUS_CANCELLED = 7;
    /** @var int The dataflow execution has been aborted. Not able to finish */
    const STATUS_ABORTED = 8;
    /** @var int Step/dataflow has completely finished. */
    const STATUS_FINALISED = 9;

    /** @var string[] Maps statuses to string indexes. */
    const STATUS_LABELS = [
        self::STATUS_NEW => 'engine_status_new',
        self::STATUS_INITIALISED => 'engine_status_initialised',
        self::STATUS_BLOCKED => 'engine_status_blocked',
        self::STATUS_WAITING => 'engine_status_waiting',
        self::STATUS_PROCESSING => 'engine_status_processing',
        self::STATUS_FLOWING => 'engine_status_flowing',
        self::STATUS_FINISHED => 'engine_status_finished',
        self::STATUS_CANCELLED => 'engine_status_cancelled',
        self::STATUS_ABORTED => 'engine_status_aborted',
        self::STATUS_FINALISED => 'engine_status_finalised',
    ];

    /** @var  array The queue of steps to be given a run. */
    public $queue;

    /** @var dataflow The dataflow defined by the user. */
    protected $dataflow;

    /** @var array The engine steps in the dataflow. */
    protected $enginesteps = [];

    /** @var array The steps that have no outputflows. */
    protected $sinks = [];

    /** @var array Caps for the flow blocks. */
    protected $flowcaps = [];

    /** @var int The status of the execution. */
    protected $status = self::STATUS_NEW;

    /** @var \Throwable The exception generated when abort occurred. */
    protected $exception = null;

    /** @var bool True if executing a dry run. */
    protected $isdryrun = false;

    /** @var bool True if executing via automation. */
    protected $automated = true;

    /**
     * Constructs the engine.
     *
     * @param dataflow $dataflow The dataflow to be executed, as defined in the editor.
     * @param bool $isdryrun global dryrun exection flag.
     * @param bool $automated Execution of this run was an automated trigger.
     */
    public function __construct(dataflow $dataflow, bool $isdryrun = false, $automated = true) {
        $this->dataflow = $dataflow;

        $this->isdryrun = $isdryrun;
        $this->automated = $automated;

        // Create engine steps for each step in the dataflow.
        foreach ($dataflow->steps as $stepdef) {
            $classname = $stepdef->type;
            $steptype = new $classname();
            $this->enginesteps[$stepdef->id] = $steptype->get_engine_step($this, $stepdef);
        }

        // Create the links between engine step.
        foreach ($this->enginesteps as $id => $enginestep) {
            $deps = $enginestep->stepdef->dependencies();
            foreach ($deps as $dep) {
                $depstep = $this->enginesteps[$dep->id];
                $enginestep->upstreams[$dep->id] = $depstep;
                $depstep->downstreams[$id] = $enginestep;
            }
        }

        // Find the sinks.
        foreach ($this->enginesteps as $enginestep) {
            if (count($enginestep->downstreams) == 0) {
                $this->sinks[] = $enginestep;
            }
        }

        // Find the flow blocks.
        $this->create_flow_caps();

        $this->log('Created');
    }

    public function initialise() {
        foreach ($this->enginesteps as $enginestep) {
            $enginestep->initialise();
        }

        // Add sinks to the execution queue.
        $this->queue = $this->sinks;
        $this->status = self::STATUS_INITIALISED;
        $this->log('Initialised. Dry run: ' . ($this->isdryrun ? 'Yes' : 'No'));
    }

    /**
     * Finds the steps that are sinks for their respective flow blocks and create flow caps for them.
     */
    protected function create_flow_caps() {
        // TODO Currently assumes flow blocks have no branches.
        $flowcaps = 0;
        foreach ($this->enginesteps as $enginestep) {
            if ($enginestep->is_flow() && count($enginestep->downstreams) == 0) {
                $steptype = new flow_cap();
                $step = new \tool_dataflows\step();
                $flowcaps++;
                $step->name = "flowcap-{$flowcaps}";
                $flowcap = $steptype->get_engine_step($this, $step);
                $this->flowcaps[] = $flowcap;
                $enginestep->downstreams['puller'] = $flowcap;
                $flowcap->upstreams[$enginestep->id] = $enginestep;
            }
        }
    }

    /**
     * Runs the data flow as a single action. This function initialises the dataflow,
     * runs the dataflow, and finalises it.
     */
    public function execute() {
        $this->initialise();

        // Check the execution conditions to ensure we can safely execute.
        $execute = $this->dataflow->get('enabled');
        // If not enabled, we can only execute under certain conditions.
        if (!$execute) {
            // We can only execute in a manual (non-automated) run, or a dry run.
            $execute = !$this->automated || $this->isdryrun;
        }

        if ($execute) {
            while ($this->status != self::STATUS_FINISHED) {
                $this->execute_step();
                if ($this->status == self::STATUS_ABORTED) {
                    return;
                }
            }
        }

        $this->finalise();
    }

    /**
     * Executes a single step. Must be initialised prior to calling. Does not finalise.
     */
    public function execute_step() {
        if ($this->status === self::STATUS_INITIALISED) {
            $this->status = self::STATUS_PROCESSING;
        }
        if ($this->status !== self::STATUS_PROCESSING) {
            throw new \moodle_exception("bad_status", "tool_dataflows");
        }
        if (count($this->queue) == 0) {
            $this->status = self::STATUS_FINISHED;
            $this->log('Finished');
        } else {
            $currentstep = array_shift($this->queue);
            $result = $currentstep->go();

            switch ($result) {
                case self::STATUS_BLOCKED:
                case self::STATUS_WAITING:
                    foreach ($currentstep->upstreams as $upstream) {
                        $this->queue[] = $upstream;
                    }
                    break;
                case self::STATUS_FINISHED:
                case self::STATUS_CANCELLED:
                    foreach ($currentstep->downstreams as $downstream) {
                        $this->queue[] = $downstream;
                    }
                    break;
                case self::STATUS_FLOWING:
                    foreach ($currentstep->downstreams as $downstream) {
                        if ($downstream->is_flow()) {
                            $this->queue[] = $downstream;
                        }
                    }
                    break;
                case self::STATUS_ABORTED:
                    $this->abort($currentstep->exception);
            }
            $currentstep->log('status ' . get_string(self::STATUS_LABELS[$result], 'tool_dataflows'));
        }
    }

    /**
     * Finalises the execution. Any remaining resources should be released.
     */
    public function finalise() {
        foreach ($this->enginesteps as $enginestep) {
            $enginestep->finalise();
        }
        $this->status = self::STATUS_FINALISED;
        $this->log('Finalised');
    }

    /**
     * Stops execution immediately. Gracefully stops all processors and iterators.
     *
     * @param \Throwable|null $exception
     * @throws \Throwable
     */
    public function abort(?\Throwable $exception = null) {
        $this->exception = $exception;
        foreach ($this->enginesteps as $enginestep) {
            $enginestep->abort();
        }
        $this->status = self::STATUS_ABORTED;
        $this->log('Aborted: ' . $exception->getMessage());
        // TODO: We may want to make this the responsibility of the caller.
        throw $exception;
    }

    /**
     * Emit a log message.
     *
     * @param string $message
     */
    public function log(string $message) {
        (new logging_context($this))->log($message);
    }

    /**
     * PHP getter.
     */
    public function __get($parameter) {
        switch ($parameter) {
            case 'status':
            case 'exception':
            case 'isdryrun':
            case 'dataflow':
                return $this->$parameter;
            case 'name':
                return $this->dataflow->name;
            default:
                throw new \moodle_exception(
                    'bad_parameter',
                    'tool_dataflows',
                    '',
                    ['parameter' => $parameter, 'classname' => self::class]);
        }
    }

    /**
     * Returns an array with all the variables available through the dataflow engine.
     *
     * Note: ideally, you could check a value set in another step via this
     * function, and returning the dataflow->variables might not always be the
     * correct choice, thus the need for this function should things be updated.
     *
     * @return  array
     */
    public function get_variables(): array {
        return $this->dataflow->variables;
    }

    /**
     * Sets a variable at the dataflow level
     *
     * Almost 'anything goes' here. Since the dataflow itself doesn't have any
     * particular restriction on config. Anything value can be set here and
     * referenced from other steps.
     *
     * TODO: add instance support.
     *
     * @param      string name of the field
     * @param      mixed value
     */
    public function set_dataflow_var($name, $value) {
        // Check if this field can be updated or not, e.g. if this was forced in config, it should not be updatable.
        // TODO: implement.

        $dataflow = $this->dataflow;
        $previous = $dataflow->config->{$name} ?? '';
        $this->log("Setting dataflow '$name' to '$value' (from '{$previous}')");
        $this->dataflow->set_var($name, $value);

        // Persists the variable to the dataflow config.
        // NOTE: This is skipped during a dry-run. Variables 'should' still be accessible as per normal.
        if (!$this->isdryrun) {
            $this->dataflow->save();
        }
    }

    /**
     * Sets a variable at the global plugin level
     *
     * Values here are - similar to the dataflow and step scope - set against a
     * config field. This however is stored via set_config and there is no
     * instance only support.
     *
     * @param      string name of the field
     * @param      mixed value
     */
    public function set_global_var($name, $value) {
        // Grabs the current config.
        $config = get_config('tool_dataflows', 'config');
        $config = Yaml::parse($config, Yaml::PARSE_OBJECT_FOR_MAP) ?: new \stdClass;

        // Updates the field in question.
        $previous = $config->{$name} ?? '';
        $config->{$name} = $value;

        // Updates the stored config.
        $config = Yaml::dump((array) $config);
        $this->log("Setting global '$name' to '$value' (from '{$previous}')");
        set_config('config', $config, 'tool_dataflows');
    }
}
