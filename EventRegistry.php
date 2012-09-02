<?php
/**
 * Copyright 2012 Benjamin Wöster. All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification, are
 * permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice, this list of
 *       conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright notice, this list
 *       of conditions and the following disclaimer in the documentation and/or other materials
 *       provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY BENJAMIN WÖSTER ``AS IS'' AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND
 * FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL BENJAMIN WÖSTER OR
 * CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
 * ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * The views and conclusions contained in the software and documentation are those of the
 * authors and should not be interpreted as representing official policies, either expressed
 * or implied, of Benjamin Wöster.
 */


/**
 * // application.config.main
 * 'import' => array(
 *   // EventInterceptor is required by EventBridgeBehavior
 *   'ext.components.event-interceptor.*',
 * ),
 * 'behaviors' => array(
 *   'eventBridge' => array(
 *     'class'  => 'ext.components.static-events.EventBridgeBehavior',
 *   ),
 * ),
 * 'components' => array(
 *   'events' => array(
 *     'class'  => 'ext.components.static-events.EventRegistry',
 *     'attach' => array(
 *       'CApplication' => array(
 *         'onBeginRequest' => function( $event ) {
 *           Yii::log( 'CApplication::onBeginRequest', CLogger::LEVEL_TRACE );
 *         },
 *         'onEndRequest' => array(
 *           function( $event ) {
 *             Yii::log( 'CApplication::onEndRequest - first handler', CLogger::LEVEL_TRACE );
 *           },
 *           function( $event ) {
 *             Yii::log( 'CApplication::onEndRequest - first handler', CLogger::LEVEL_TRACE );
 *           },
 *         ),
 *       ),
 *     ),
 *   ),
 * ),
 *
 * $events = Yii::app()->events;
 *
 * $events->attach( 'CActiveRecord', 'onAfterConstruct', $callback );
 * $events->detach( 'CActiveRecord', 'onAfterConstruct', $callback );
 *
 * @author Benjamin
 */
class EventRegistry extends CApplicationComponent
{

  private $_callbacks = array();

  public function setAttach( array $aConfig )
  {
    foreach ($aConfig as $className => $aEvents)
    {
      foreach ($aEvents as $event => $handler)
      {
        if (is_string($handler))
        {
          $this->attach( $className, $event, $handler );
        }
        else if(is_callable($handler,true))
        {
          $this->attach( $className, $event, $handler );
        }
        else if (is_array($handler))
        {
          foreach ($handler as $h) {
            $this->attach( $className, $event, $h );
          }
        }
        else
        {
          throw new CException( "Invalid configuration for '{$className}.{$event}.'" );
        }
      }
    }
  }

  public function raiseStaticEvent( $class, $event, $eventInstance )
  {
		$eventName = strtolower($event);

		if (isset($this->_callbacks[$class]) && isset($this->_callbacks[$class][$eventName]))
		{
			foreach ($this->_callbacks[$class][$eventName] as $handler)
			{
				if(is_string($handler))
					call_user_func($handler,$eventInstance);
				else if(is_callable($handler,true))
				{
					if(is_array($handler))
					{
						// an array: 0 - object, 1 - method name
						list($object,$method)=$handler;
						if(is_string($object))	// static method call
							call_user_func($handler,$eventInstance);
						else if(method_exists($object,$method))
							$object->$method($eventInstance);
						else
							throw new CException(Yii::t('yii','Event "{class}.{event}" is attached with an invalid handler "{handler}".',
								array('{class}'=>$class, '{event}'=>$event, '{handler}'=>$handler[1])));
					}
					else // PHP 5.3: anonymous function
						call_user_func($handler,$eventInstance);
				}
				else
					throw new CException(Yii::t('yii','Event "{class}.{event}" is attached with an invalid handler "{handler}".',
						array('{class}'=>$class, '{event}'=>$event, '{handler}'=>gettype($handler))));
				// stop further handling if param.handled is set true
				if(($eventInstance instanceof CEvent) && $eventInstance->handled)
					return;
			}
		}
		else if(YII_DEBUG && !$this->componentHasEvent($class,$event))
			throw new CException(Yii::t('yii','Event "{class}.{event}" is not defined.',
				array('{class}'=>$class, '{event}'=>$event)));
  }

  /**
   * $events->attach( 'CActiveRecord', 'onAfterConstruct' ) = $callback;
   *
   * @param string $class
   * @param string $event
   * @param callback $handler
   */
	public function attach( $class, $event, $handler )
	{
    $this->getStaticEventHandlers($class,$event)->add( $handler );
	}

  /**
   * $events->detach( 'CActiveRecord', 'onAfterConstruct', $callback );
   *
   * @param string $class
   * @param string $event
   * @param callback $handler
	 * @return boolean if the detachment process is successful
   */
  public function detach( $class, $event, $handler )
  {
		if ($this->hasStaticEventHandler($class,$event)) {
      return $this->getStaticEventHandlers($class,$event)->remove($handler) !== false;
    }

    return false;
  }

	/**
	 * Returns the list of attached event handlers for an event.
	 * @param string $class the class name
	 * @param string $event the event name
	 * @return CList list of attached event handlers for the event
	 * @throws CException if the event is not defined
	 */
  public function getStaticEventHandlers( $class, $event )
  {
    if ($this->componentHasEvent($class,$event))
    {
			$eventName = strtolower( $event );

			if (!isset($this->_callbacks[$class])
          || !isset($this->_callbacks[$class][$eventName]))
      {
				$this->_callbacks[$class][$eventName] = new CList();
      }

			return $this->_callbacks[$class][$eventName];
    }
    else
    {
			throw new CException( Yii::t(
        'yii',
        'Event "{class}.{event}" is not defined.',
				array(
          '{class}' => $class,
          '{event}' => $event,
        )
      ));
    }
  }

	/**
	 * Checks whether the named event has attached handlers.
	 * @param string $name the event name
	 * @return boolean whether an event has been attached one or several handlers
	 */
	public function hasStaticEventHandler( $class, $event )
	{
		$eventName = strtolower( $event );
		return isset($this->_callbacks[$class])
      && isset($this->_callbacks[$class][$eventName])
      && $this->_callbacks[$class][$eventName]->getCount() > 0;
	}


  private function componentHasEvent( $class, $event )
  {
		return !strncasecmp($event,'on',2) && method_exists($class,$event);
  }

}
