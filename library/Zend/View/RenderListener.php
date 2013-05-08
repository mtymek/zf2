<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2013 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\View;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\ListenerAggregateInterface;
use Zend\View\Model\ModelInterface as Model;
use Zend\View\Renderer\TreeRendererInterface;

class RenderListener implements ListenerAggregateInterface
{
    /**
     * @var \Zend\Stdlib\CallbackHandler[]
     */
    protected $listeners = array();

    /**
     * Attach one or more listeners
     *
     * Implementors may add an optional $priority argument; the EventManager
     * implementation will pass this to the aggregate.
     *
     * @param EventManagerInterface $events
     *
     * @return void
     */
    public function attach(EventManagerInterface $events)
    {
        $this->listeners[] = $events->attach(ViewEvent::EVENT_RENDER, array($this, 'onRenderModel'));
    }

    /**
     * Detach all previously attached listeners
     *
     * @param EventManagerInterface $events
     *
     * @return void
     */
    public function detach(EventManagerInterface $events)
    {
        foreach ($this->listeners as $index => $listener) {
            if ($events->detach($listener)) {
                unset($this->listeners[$index]);
            }
        }
    }

    /**
     * @param ViewEvent $event
     * @return mixed
     */
    public function onRenderModel(ViewEvent $event)
    {
        $renderer = $event->getRenderer();
        $model    = $event->getModel();

        // If we have children, render them first, but only if:
        // a) the renderer does not implement TreeRendererInterface, or
        // b) it does, but canRenderTrees() returns false
        if ($model->hasChildren()
            && (!$renderer instanceof TreeRendererInterface
                || !$renderer->canRenderTrees())
        ) {
            $this->renderChildren($event, $model);
        }

        // Reset the model, in case it has changed, and set the renderer
        $event->setModel($model);
        $event->setRenderer($renderer);

        $output = $renderer->render($model);
        $event->setOutput($output);

        return $output;
    }

    /**
     * Loop through children, rendering each
     *
     * @param ViewEvent $event
     * @param  Model $model
     * @throws Exception\DomainException
     * @return void
     */
    protected function renderChildren(ViewEvent $event, Model $model)
    {
        foreach ($model as $child) {
            if ($child->terminate()) {
                throw new Exception\DomainException('Inconsistent state; child view model is marked as terminal');
            }
            $child->setOption('has_parent', true);
            $result = $event->getTarget()->render($child);
            $child->setOption('has_parent', null);
            $capture = $child->captureTo();
            if (!empty($capture)) {
                if ($child->isAppend()) {
                    $oldResult = $model->{$capture};
                    $model->setVariable($capture, $oldResult . $result);
                } else {
                    $model->setVariable($capture, $result);
                }
            }
        }
    }

}