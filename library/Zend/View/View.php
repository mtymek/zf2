<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2013 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\View;

use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\Stdlib\RequestInterface as Request;
use Zend\Stdlib\ResponseInterface as Response;
use Zend\View\Model\ModelInterface as Model;
use Zend\View\Renderer\RendererInterface as Renderer;

class View implements EventManagerAwareInterface
{
    /**
     * @var EventManagerInterface
     */
    protected $events;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Response
     */
    protected $response;

    /**
     * Set MVC request object
     *
     * @param  Request $request
     * @return View
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Set MVC response object
     *
     * @param  Response $response
     * @return View
     */
    public function setResponse(Response $response)
    {
        $this->response = $response;
        return $this;
    }

    /**
     * Get MVC request object
     *
     * @return null|Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Get MVC response object
     *
     * @return null|Response
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Set the event manager instance
     *
     * @param  EventManagerInterface $events
     * @return View
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers(array(
            __CLASS__,
            get_class($this),
        ));
        $this->events = $events;
        return $this;
    }

    /**
     * Retrieve the event manager instance
     *
     * Lazy-loads a default instance if none available
     *
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (!$this->events instanceof EventManagerInterface) {
            $this->setEventManager(new EventManager());
        }

        $this->attachDefaultListeners($this->events);

        return $this->events;
    }

    /**
     * Add a rendering strategy
     *
     * Expects a callable. Strategies should accept a ViewEvent object, and should
     * return a Renderer instance if the strategy is selected.
     *
     * Internally, the callable provided will be subscribed to the "renderer"
     * event, at the priority specified.
     *
     * @param  callable $callable
     * @param  int $priority
     * @return View
     */
    public function addRenderingStrategy($callable, $priority = 1)
    {
        $this->getEventManager()->attach(ViewEvent::EVENT_RENDERER, $callable, $priority);
        return $this;
    }

    /**
     * Add a response strategy
     *
     * Expects a callable. Strategies should accept a ViewEvent object. The return
     * value will be ignored.
     *
     * Typical usages for a response strategy are to populate the Response object.
     *
     * Internally, the callable provided will be subscribed to the "response"
     * event, at the priority specified.
     *
     * @param  callable $callable
     * @param  int $priority
     * @return View
     */
    public function addResponseStrategy($callable, $priority = 1)
    {
        $this->getEventManager()->attach(ViewEvent::EVENT_RESPONSE, $callable, $priority);
        return $this;
    }

    protected function attachDefaultListeners(EventManager $eventManager)
    {
        // @todo do via factory? (also avoids instantiating stuff more than once)
        if (count($eventManager->getListeners(ViewEvent::EVENT_RENDER)) > 0) {
            return;
        }

        $listener = new RenderListener();
        $eventManager->attachAggregate($listener);
    }

    /**
     * Render the provided model.
     *
     * Internally, the following workflow is used:
     *
     * - Trigger the "renderer" event to select a renderer.
     * - Call the selected renderer with the provided Model
     * - Trigger the "response" event
     *
     * @triggers renderer(ViewEvent)
     * @triggers renderer.post(ViewEvent)
     * @triggers render(ViewEvent)
     * @triggers response(ViewEvent)
     * @param  Model $model
     * @throws Exception\RuntimeException
     * @return void
     */
    public function render(Model $model)
    {
        $event = $this->getEvent();
        $event->setModel($model);
        $event->setResult('');

        // resolve renderer
        $events  = $this->getEventManager();
        $results = $events->trigger(ViewEvent::EVENT_RENDERER, $event, function ($result) {
                return ($result instanceof Renderer);
            });
        $renderer = $results->last();

        if (!$renderer instanceof Renderer) {
            throw new Exception\RuntimeException(sprintf(
                '%s: no renderer selected!',
                __METHOD__
            ));
        }

        $event->setRenderer($renderer);
        $events->trigger(ViewEvent::EVENT_RENDERER_POST, $event);

        // render model
        $this->getEventManager()->trigger(
            ViewEvent::EVENT_RENDER,
            $event
        );

        // If this is a child model, return the rendered content; do not
        // invoke the response strategy.
        $options = $model->getOptions();

        if (array_key_exists('has_parent', $options) && $options['has_parent']) {
            return $event->getOutput();
        }

        $event->setResult($event->getOutput());
        $events->trigger(ViewEvent::EVENT_RESPONSE, $event);
    }

    /**
     * Create and return ViewEvent used by render()
     *
     * @return ViewEvent
     */
    protected function getEvent()
    {
        $event = new ViewEvent();
        $event->setTarget($this);
        if (null !== ($request = $this->getRequest())) {
            $event->setRequest($request);
        }
        if (null !== ($response = $this->getResponse())) {
            $event->setResponse($response);
        }
        return $event;
    }
}
