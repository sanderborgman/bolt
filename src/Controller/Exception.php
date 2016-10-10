<?php

namespace Bolt\Controller;

use Bolt\Filesystem\Exception\IOException;
use Bolt\Filesystem\Handler\File;
use Bolt\Helpers\Html;
use Carbon\Carbon;
use Cocur\Slugify\Slugify;
use Silex\Application;
use Silex\ControllerCollection;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Exception controller.
 *
 * @internal Do not extend this class! (yet)
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class Exception extends Base implements ExceptionControllerInterface
{
    /**
     * {@inheritdoc}
     */
    protected function addRoutes(ControllerCollection $c)
    {
        $c->value(Zone::KEY, Zone::FRONTEND);

        $this->app->after([$this, 'afterKernelException'], Application::LATE_EVENT);
    }

    /**
     * @param \Exception $exception
     *
     * @return Response
     */
    public function genericException(\Exception $exception)
    {
        if ($this->app === null) {
            throw new \RuntimeException('Exception controller being used outside of request cycle.');
        }

        $message = $exception->getMessage();
        $context = $this->getContextArray($exception);
        $context['message'] = $message;

        $html = $this->app['twig']->render('@bolt/exception/general.twig', $context);
        $response = new Response($html, Response::HTTP_OK);
        $response->headers->set('X-Debug-Exception-Handled', time());

        return $response;
    }

    /**
     * Route for kernel exception handling.
     *
     * @param GetResponseForExceptionEvent $event
     *
     * @return Response
     */
    public function kernelException(GetResponseForExceptionEvent $event)
    {
        if ($this->app === null) {
            throw new \RuntimeException('Exception controller being used outside of request cycle.');
        }

        $exception = $event->getException();
        $message = $exception->getMessage();
        if ($exception instanceof HttpExceptionInterface && !Zone::isBackend($event->getRequest())) {
            $message = "The page could not be found, and there is no 'notfound' set in 'config.yml'. Sorry about that.";
        }

        $context = $this->getContextArray($exception);
        $context['type'] = 'general';
        $context['message'] = $message;

        $html = $this->app['twig']->render('@bolt/exception/general.twig', $context);
        $response = new Response($html, Response::HTTP_OK);
        $response->headers->set('X-Debug-Exception-Handled', time());

        return $response;
    }

    /**
     * Pre-send response handling middleware callback.
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return RedirectResponse|null
     */
    public function afterKernelException(Request $request, Response $response)
    {
        if (!$response->headers->has('X-Debug-Exception-Handled')) {
            return null;
        }

        $hasToken = $response->headers->has('X-Debug-Token');
        $redirectProfiler = $this->app['config']->get('general/debug_error_use_profiler');
        if (!$hasToken || !$redirectProfiler) {
            return null;
        }

        $token = $response->headers->get('X-Debug-Token');
        $link = $this->app['url_generator']->generate(
            '_profiler',
            ['token' => $token, 'panel' => 'exception'],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return new RedirectResponse($link);
    }

    /**
     * @param string     $platform
     * @param \Exception $previous
     *
     * @return Response
     */
    public function databaseConnect($platform, \Exception $previous)
    {
        if ($this->app === null) {
            throw new \RuntimeException('Exception controller being used outside of request cycle.');
        }

        $context = $this->getContextArray($previous);
        $context['type'] = 'connect';
        $context['platform'] = $platform;

        $html = $this->app['twig']->render('@bolt/exception/database/exception.twig', $context);
        $response = new Response($html);

        return new Response($response, Response::HTTP_OK);
    }

    /**
     * @param string $subtype
     * @param string $name
     * @param string $driver
     * @param string $parameter
     *
     * @return Response
     */
    public function databaseDriver($subtype, $name, $driver, $parameter = null)
    {
        if ($this->app === null) {
            throw new \RuntimeException('Exception controller being used outside of request cycle.');
        }

        $context = $this->getContextArray();
        $context['type'] = 'driver';
        $context['subtype'] = $subtype;
        $context['name'] = $name;
        $context['driver'] = $driver;
        $context['parameter'] = $parameter;

        $html = $this->app['twig']->render('@bolt/exception/database/exception.twig', $context);

        return new Response($html, Response::HTTP_OK);
    }

    /**
     * @param string $subtype
     * @param string $path
     * @param string $error
     *
     * @return Response
     */
    public function databasePath($subtype, $path, $error)
    {
        if ($this->app === null) {
            throw new \RuntimeException('Exception controller being used outside of request cycle.');
        }

        $context = $this->getContextArray();
        $context['type'] = 'path';
        $context['subtype'] = $subtype;
        $context['path'] = $path;
        $context['error'] = $error;

        $html = $this->app['twig']->render('@bolt/exception/database/exception.twig', $context);

        return new Response($html, Response::HTTP_OK);
    }

    /**
     * System check exceptions.
     *
     * @param string $type
     * @param array  $messages
     * @param array  $context
     *
     * @return Response
     */
    public function systemCheck($type, $messages = [], $context = [])
    {
        if ($this->app === null) {
            throw new \RuntimeException('Exception controller being used outside of request cycle.');
        }

        $context['config'] = $this->app['config'];
        $context['paths'] = $this->app['resources']->getPaths();
        $context['debug'] = $this->app['debug'];
        $context['type'] = $type;
        $context['messages'] = $messages;

        $html = $this->app['twig']->render('@bolt/exception/system/exception.twig', $context);

        return new Response($html, Response::HTTP_OK);
    }

    /**
     * Get a pre-packaged Twig context array.
     *
     * @param \Exception $exception
     *
     * @return array
     */
    protected function getContextArray(\Exception $exception = null)
    {
        if ($exception) {
            try {
                $this->saveException($exception);
            } catch (IOException $e) {
                //
            }
        }

        $loggedOnUser = (bool) $this->app['users']->getCurrentUser() ?: false;
        $showLoggedOff = (bool) $this->app['config']->get('general/debug_show_loggedoff', false);

        $filename = $exception ? $exception->getFile() : null;
        $linenumber = $exception ? $exception->getLine() : null;

        if ($filename && $linenumber) {
            $snippet = implode('', array_slice(file($filename), max(0, $linenumber - 6), 11));
        } else {
            $snippet = false;
        }

        return [
            'debug'     => ($this->app['debug'] && ($loggedOnUser || $showLoggedOff)),
            'exception' => [
                'object'       => $exception,
                'class'        => $exception ? get_class($exception) : null,
                'filename'     => $filename,
                'filebasename' => basename($filename),
                'trace'        => $exception ? $this->getSafeTrace($exception) : null,
                'snippet'      => $snippet,
            ],
        ];
    }

    /**
     * Get the exception trace that is safe to display publicly.
     *
     * @param \Exception  $exception
     *
     * @return array
     */
    protected function getSafeTrace(\Exception $exception)
    {
        if (!$this->app['debug'] && !($this->app['session']->isStarted() && $this->app['session']->has('authentication'))) {
            return [];
        }

        $rootPath = $this->app['resources']->getPath('root');
        $trace = $exception->getTrace();
        foreach ($trace as $key => $value) {
            $simpleargs = [];
            foreach ($trace[$key]['args'] as $arg) {
                $type = gettype($arg);
                switch ($type) {
                    case 'string':
                        $simpleargs[] = sprintf('<span>"%s"</span>', Html::trimText($arg, 30));
                        break;

                    case 'integer':
                    case 'float':
                        $simpleargs[] = sprintf('<span>%s</span>', $arg);
                        break;

                    case 'object':
                        $classname = get_class($arg);
                        $shortname = (new \ReflectionClass($arg))->getShortName();
                        $simpleargs[] = sprintf('<abbr title="%s">%s</abbr>', $classname, $shortname);
                        break;

                    case 'boolean':
                        $simpleargs[] = $arg ? '[true]' : '[false]';
                        break;

                    default:
                        $simpleargs[] = '[' . $type . ']';
                }
            }

            $trace[$key]['simpleargs'] = $simpleargs;

            // Don't display the full path, trim 64-char hexadecimal filenames.
            if (isset($trace[$key]['file'])) {
                $trace[$key]['file'] = str_replace($rootPath, '[root]', $trace[$key]['file']);
                $trace[$key]['file'] = preg_replace('/([0-9a-f]{16})[0-9a-f]{48}/i', '\1…', $trace[$key]['file']);
            }
        }

        return $trace;
    }

    /**
     * Attempt to save the serialised exception if in debug mode.
     *
     * @param \Exception $exception
     */
    protected function saveException(\Exception $exception)
    {
        if ($this->app['debug'] !== true) {
            return;
        }

        $environment = $this->app['environment'];
        $serialised = serialize(FlattenException::create($exception));

        $sourceFile = Slugify::create()->slugify($exception->getFile());
        $fileName = sprintf('%s-%s.exception', Carbon::now()->format('Ymd-Hmi'), substr($sourceFile, -102));
        $fullPath = sprintf('%s/exception/%s', $environment, $fileName);

        $cacheFilesystem = $this->app['filesystem']->getFilesystem('cache');
        $file = new File($cacheFilesystem, $fullPath);
        $file->write($serialised);
    }
}
