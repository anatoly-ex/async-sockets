<?php
/**
 * Async sockets
 *
 * @copyright Copyright (c) 2015, Efimov Evgenij <edefimov.it@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace AsyncSockets\Socket;

use AsyncSockets\Exception\FrameSocketException;
use AsyncSockets\Exception\NetworkSocketException;
use AsyncSockets\Frame\FrameInterface;
use AsyncSockets\Frame\NullFrame;
use AsyncSockets\Socket\Assistant\FrameProcessor;

/**
 * Class AbstractSocket
 */
abstract class AbstractSocket implements SocketInterface
{
    /**
     * Socket buffer size
     */
    const SOCKET_BUFFER_SIZE = 8192;

    /**
     * Amount of attempts to set data
     */
    const SEND_ATTEMPTS = 10;

    /**
     * Delay for select operation, microseconds
     */
    const SELECT_DELAY = 25000;

    /**
     * Disconnected state
     */
    const STATE_DISCONNECTED = 0;

    /**
     * Connected state
     */
    const STATE_CONNECTED = 1;

    /**
     * This socket resource
     *
     * @var resource
     */
    private $resource;

    /**
     * Socket state
     *
     * @var int
     */
    private $state;

    /**
     * Flag whether this socket is blocking
     *
     * @var bool
     */
    private $isBlocking = true;

    /**
     * Frame processor
     *
     * @var FrameProcessor
     */
    private $frameProcessor;

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Create certain socket resource
     *
     * @param string   $address Network address to open in form transport://path:port
     * @param resource $context Valid stream context created by function stream_context_create or null
     *
     * @return resource
     */
    abstract protected function createSocketResource($address, $context);

    /** {@inheritdoc} */
    public function open($address, $context = null)
    {
        $this->close();

        $this->resource = $this->createSocketResource($address, $context ?: stream_context_get_default());

        $result = false;
        if (is_resource($this->resource)) {
            $result = true;
            $meta   = stream_get_meta_data($this->resource);
            if (isset($meta[ 'blocked' ]) && $meta[ 'blocked' ] != $this->isBlocking) {
                $this->setBlocking($this->isBlocking);
            }
        }

        return $result;
    }

    /** {@inheritdoc} */
    public function close()
    {
        $this->state          = self::STATE_DISCONNECTED;
        $this->frameProcessor = null;
        if ($this->resource) {
            stream_socket_shutdown($this->resource, STREAM_SHUT_RDWR);
            fclose($this->resource);
            $this->resource = null;
        }
    }

    /** {@inheritdoc} */
    public function read(FrameInterface $frame = null, ChunkSocketResponse $previousResponse = null)
    {
        $this->setConnectedState();
        $frame = $previousResponse ? $previousResponse->getFrame() : $frame;
        $frame = $frame ?: new NullFrame();

        $result        = '';
        $isDataChanged = false;
        $isEndOfFrame  = false;
        do {
            if ($this->isFullFrameRead($frame)) {
                $isEndOfFrame = true;
                break;
            }

            // work-around https://bugs.php.net/bug.php?id=52602
            $rawData = stream_socket_recvfrom($this->resource, self::SOCKET_BUFFER_SIZE, MSG_PEEK);
            switch (true) {
                case $rawData === '':
                    $isEndOfFrame = $frame instanceof NullFrame || $isEndOfFrame;
                    break 2;
                case $rawData === false:
                    break 2;
                default:
                    $actualData = $this->readActualData();
                    $result     = $this->getFrameProcessor()->processReadFrame($frame, $actualData, $result);

                    $isDataChanged = true;
                    $isEndOfFrame = !($frame instanceof NullFrame) && $frame->isEof();
            }
        } while (!$isEndOfFrame);

        if ($isEndOfFrame) {
            return new SocketResponse($frame, (string) $previousResponse . $result);
        } else {
            return $isDataChanged || !$previousResponse ?
                new ChunkSocketResponse($frame, $result, $previousResponse) :
                $previousResponse;
        }
    }

    /** {@inheritdoc} */
    public function write($data)
    {
        $this->setConnectedState();

        $result       = 0;
        $dataLength   = strlen($data);
        $microseconds = self::SELECT_DELAY;
        $attempts     = self::SEND_ATTEMPTS;

        do {
            $write    = [ $this->resource ];
            $nomatter = null;
            $select   = stream_select($nomatter, $write, $nomatter, 0, $microseconds);
            $this->throwNetworkSocketExceptionIf($select === false, 'Failed to send data.');

            $bytesWritten = $write ? $this->writeActualData($data) : 0;
            $attempts     = $bytesWritten === 0 ? $attempts - 1 : self::SEND_ATTEMPTS;

            $this->throwNetworkSocketExceptionIf(
                !$attempts && $result !== $dataLength,
                'Failed to send data.'
            );

            $data = substr($data, $bytesWritten);
            $result += $bytesWritten;
        } while ($result < $dataLength && $data !== false);

        return $result;
    }

    /** {@inheritdoc} */
    public function setBlocking($isBlocking)
    {
        if (is_resource($this->resource)) {
            $result = stream_set_blocking($this->resource, $isBlocking ? 1 : 0);
            $this->throwNetworkSocketExceptionIf(
                $result === false,
                'Failed to switch ' . ($isBlocking ? '' : 'non-') . 'blocking mode.'
            );
        } else {
            $result = true;
        }

        $this->isBlocking = (bool) $isBlocking;

        return $result;
    }

    /** {@inheritdoc} */
    public function getStreamResource()
    {
        return $this->resource;
    }

    /**
     * Throw network operation exception
     *
     * @param bool   $condition Condition, which must evaluates to true for throwing exception
     * @param string $message Exception message
     *
     * @return void
     * @throws NetworkSocketException
     */
    protected function throwNetworkSocketExceptionIf($condition, $message)
    {
        if ($condition) {
            throw new NetworkSocketException($this, $message);
        }
    }

    /**
     * Read actual data from socket
     *
     * @return string
     */
    private function readActualData()
    {
        $data = fread($this->resource, self::SOCKET_BUFFER_SIZE);
        $this->throwNetworkSocketExceptionIf($data === false, 'Failed to read data.');

        if ($data === 0) {
            $this->throwExceptionIfNotConnected('Remote connection has been lost.');
        }

        return $data;
    }

    /**
     * Writes given data to socket
     *
     * @param string $data Data to write
     *
     * @return int Amount of written bytes
     */
    private function writeActualData($data)
    {
        $test = stream_socket_sendto($this->resource, '');
        $this->throwNetworkSocketExceptionIf($test !== 0, 'Failed to send data.');

        $written = fwrite($this->resource, $data, strlen($data));
        $this->throwNetworkSocketExceptionIf($written === false, 'Failed to send data.');

        if ($written === 0) {
            $this->throwExceptionIfNotConnected('Remote connection has been lost.');
        }

        return $written;
    }

    /**
     * Verify, that we are in connected state
     *
     * @return void
     */
    private function setConnectedState()
    {
        if (!is_resource($this->resource)) {
            $message = $this->state === self::STATE_CONNECTED ?
                'Connection was unexpectedly closed.' :
                'Can not start io operation on uninitialized socket.';
            $this->throwNetworkSocketExceptionIf(true, $message);
        }

        if ($this->state !== self::STATE_CONNECTED) {
            $this->throwExceptionIfNotConnected('Connection refused.');

            $this->state = self::STATE_CONNECTED;
        }
    }

    /**
     * Checks that we are in connected state
     *
     * @param string $message Message to pass in exception
     *
     * @return void
     */
    private function throwExceptionIfNotConnected($message)
    {
        $name = stream_socket_get_name($this->resource, true);
        $this->throwNetworkSocketExceptionIf($name === false, $message);
    }

    /**
     * Checks whether all frame data is read
     *
     * @param FrameInterface $frame Frame object to check
     *
     * @return bool
     * @throws FrameSocketException If socket data is ended and frame eof is not reached
     */
    private function isFullFrameRead(FrameInterface $frame)
    {
        $read     = [ $this->resource ];
        $nomatter = null;
        $select   = stream_select($read, $nomatter, $nomatter, 0, self::SELECT_DELAY);
        $this->throwNetworkSocketExceptionIf($select === false, 'Failed to read data.');

        if ($select === 0) {
            if ($frame->isEof()) {
                return true;
            } else {
                throw new FrameSocketException($frame, $this, 'Failed to receive desired frame.');
            }
        }

        return false;
    }

    /**
     * Return FrameProcessor object
     *
     * @return FrameProcessor
     */
    private function getFrameProcessor()
    {
        if (!$this->frameProcessor) {
            $this->frameProcessor = new FrameProcessor();
        }

        return $this->frameProcessor;
    }
}
