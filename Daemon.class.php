<?
/* Daemon.class.php - Class for daemonizing applications
 * Copyright (C) 2007 Erik Osterman <e@osterman.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/* File Authors:
 *   Erik Osterman <e@osterman.com>
 */


// Very important... pcntl_signal does not work unless you declare ticks
declare( ticks = 1 );

class Daemon
{
  private $user;
  private $group;
  private $uid;
  private $gid;   
  private $pid;   // Current processes PID (master/child)
  private $cwd;
  private $child;   // Boolean flag indicating whether or not process is a child
  private $children;  // Array of children
  private $signals; // Signals to watch
  private $callback;
  private $max_procs; // Max processes
  private $expire;  // When the process should expire
  
  public function __construct( $callback = null )
  {
    ob_implicit_flush();

    if( is_null($callback) )
      $this->callback = Array( $this, 'child' );
    elseif( !is_array($callback) )
      throw new Exception( get_class($this) . "::__construct expected callback to be an array. Got " . Debug::describe($callback) );
    else
      $this->callback = $callback;
    
    $this->user      = null;
    $this->uid       = null;
    $this->group     = null;
    $this->gid       = null;
    $this->pid       = posix_getpid();
    $this->cwd       = '/'; 
    $this->child     = false;   // We are by default the master
    $this->children  = Array();
    $this->max_procs = 0;     // 0 => unlimited
    $this->expire    = 0;     // CPU timelimit (not execution time limit)
    $this->signals   = Array( SIGHUP  => 'signal_catcher',
                              SIGCHLD => 'signal_catcher',
                              SIGTERM => 'signal_catcher',
                              SIGTSTP => 'signal_catcher' ) ;

    
    foreach( $this->signals AS $signal => $callback )
      pcntl_signal( $signal, Array( $this, $callback ) );

    register_shutdown_function( Array( $this, 'cleanup') );
  }

  public function __destruct()
  {
    unset($this->user);
    unset($this->group);
    unset($this->uid);
    unset($this->gid);
    unset($this->pid);
    unset($this->cwd);
    unset($this->child);
    unset($this->children);
    unset($this->signals);
    unset($this->callback);
    unset($this->max_procs);
    unset($this->expire);
  }

  public function __get( $property )
  {
    switch( $property )
    {
      case 'child':
        return $this->child;
      case 'master':
        return ! $this->child;
      case 'children':
        return $this->children;
      case 'procs':
        return count($this->children);
      case 'max_procs':
        return $this->max_procs;
      default:
        throw new Exception( get_class($this) . "::$property not defined");
    }
  }

  public function __set( $property, $value )
  {
    switch( $property )
    {
      case 'cwd':
        return $this->cwd = $value;
      case 'user':
        $this->uid = $this->get_uid($value);
        $this->user = $value;
        return $value;
        
      case 'group':
        $this->gid = $this->get_gid($value);
        $this->group = $value;
        return $value;
        
      case 'expire':
        if( Type::integer( $value ) )
          return $this->expire = $value;
        else
          throw new Exception( get_class($this) . "::$property should be an integer. Got " . Debug::describe($value) );
      case 'max_procs':
        if( Type::integer( $value ) )
          return $this->max_procs = $value;
        else
          throw new Exception( get_class($this) . "::$property should be an integer. Got " . Debug::describe($value) );
      default:
        throw new Exception( get_class($this) . "::$property cannot be set");
    }
      
  }

  public function __unset( $property )
  {
    throw new Exception( get_class($this) . "::$property cannot be unset");
  }
  

  public function child()
  {
     throw new Exception( get_class($this) . "::child process not defined");
  }

  public function get_uid( $user )
  {
    if( $attributes = posix_getpwnam( $user ) )
       return $attributes['uid'];
    else
      throw new FatalException( get_class($this) . "::get_uid user '$user' not found");
  }

  public function get_gid( $group )
  {
    if( $attributes = posix_getgrnam( $group ) )
       return $attributes['gid'];
    else 
      throw new FatalException( get_class($this) . "::get_gid group '$group' not found");
  }

  public function timeout( $limit = 0 )
  {
    set_time_limit( $limit );
  }

  public function daemonize()
  {
    // Fork child process
    $this->thread();
    if( $this->master ) 
      exit(0);      // We're the parent, so the parent should exita
    $this->child = false; // We're the new master
    return true;  // Not reached.
  }

  public function kill( $pid, $sig = SIGTERM )
  {
    if( in_array( $pid, $this->children ) )
    {
      posix_kill( $pid, $sig );

      // Certain signals apparently don't get reaped, so we'll do it manually
      // FIXME don't know if more signals don't get reaped.
      if( in_array( $sig, Array( SIGKILL ) ) )
        $this->children = array_diff($this->children, Array( $pid ) );
    } else
      throw new Exception( get_class($this) . "::kill[$sig] can only be sent to our children"); 
  }
  
  public function thread()
  {
    while( $this->max_procs != 0 && $this->procs >= $this->max_procs )
    {
  //    print "Sleeping...\n";
      usleep( 0.100 * 1000000 );
    }
    
    $pid = pcntl_fork();
    if( $pid == -1 ) // error
    {
      throw new FatalException( get_class($this) . "::fork failed to fork");
    } elseif( $pid ) { 
      // Master
      array_push($this->children, $pid);
    } else {
      $this->child = true;
      $this->pid = posix_getpid();
      $this->timeout($this->expire);
    }
  }

  public function fork()
  {
    $args = func_get_args();
    $this->thread();
    if( $this->child )
    {
      call_user_func_array( $this->callback, $args );
      // Extemely important to exit, or the rest of the PHP script will execute in the child as well
      exit(0);
    }
  }

  public function sanitize()
  {
    // Sanitize our environment (This is not mandatory, as binding to privileged sockets requires requires root access.)
    // Set identity
     
    if( !is_null($this->gid) )
      $this->sgid();  // Set GID must be called before Set UID
    
    if( !is_null($this->uid) )
      $this->suid();
    

    // Make current process a session leader
    if( ! posix_setsid() )
      throw new FatalException( get_class($this) . "::sanitize failed to become session leader");

    fclose(STDIN);
    fclose(STDOUT);
    fclose(STDERR);

    // CWD
    if( !is_null($this->cwd) )
      @chdir($this->cwd);

    //  Clear all file creation masks
    umask(0);
  }

  public function suid()
  {
    if( posix_setuid($this->uid) )
      return true;
    else
      throw new FatalException( get_class($this) . "::suid could not set UID to " . $this->uid);
  }

  public function sgid()
  {
    if( posix_setgid($this->gid)  )
      return true;
    else
      throw new FatalException( get_class($this) . "::sgid could not set GID to " . $this->gid);
  }

  public function sig_term()
  {
    exit();
  }

  public function sig_child()
  {
    return $this->dispatch(WNOHANG);
  }


  // Waits on all children
  public function dispatch( $flags = 0 )
  {
    $reaped = 0;
    //while( $pid = pcntl_waitpid(-1, $status, WNOHANG) > 0 )
    // pcntl_wait returns one pid per call. WNOHANG makes it non-blocking, so if there are no 
    //  more children, it simply returns 0 or -1 upon error. 
    while( ( $pid = pcntl_wait($status, $flags) ) > 0 )
    {
      //print_r($this->children);
      //print "PID: $pid\n";
      $this->children = array_diff($this->children, Array( $pid ) );
      $reaped++;
    }
    return $reaped;
  }

  public static function signal_string( $sig )
  {
    switch($sig) 
    {
      case SIGFPE:    return 'SIGFPE';
      case SIGSTOP:   return 'SIGSTOP';
      case SIGHUP:    return 'SIGHUP';
      case SIGINT:    return 'SIGINT';
      case SIGQUIT:   return 'SIGQUIT';
      case SIGILL:    return 'SIGILL';
      case SIGTRAP:   return 'SIGTRAP';
      case SIGABRT:   return 'SIGABRT';
      case SIGIOT:    return 'SIGIOT';
      case SIGBUS:    return 'SIGBUS';
      case SIGPOLL:   return 'SIGPOLL';
      case SIGSYS:    return 'SIGSYS';
      case SIGCONT:   return 'SIGCONT';
      case SIGUSR1:   return 'SIGUSR1';
      case SIGUSR2:   return 'SIGUSR2';
      case SIGSEGV:   return 'SIGSEGV';
      case SIGPIPE:   return 'SIGPIPE';
      case SIGALRM:   return 'SIGALRM';
      case SIGTERM:   return 'SIGTERM';
      case SIGSTKFLT: return 'SIGSTKFLT';
      case SIGCHLD:   return 'SIGCHLD';
      case SIGCLD:    return 'SIGCLD';
      case SIGIO:     return 'SIGIO';
      case SIGKILL:   return 'SIGKILL';
      case SIGTSTP:   return 'SIGTSTP';
      case SIGTTIN:   return 'SIGTTIN';
      case SIGTTOU:   return 'SIGTTOU';
      case SIGURG:    return 'SIGURG';
      case SIGXCPU:   return 'SIGXCPU';
      case SIGXFSZ:   return 'SIGXFSZ';
      case SIGVTALRM: return 'SIGVTALRM';
      case SIGPROF:   return 'SIGPROF';
      case SIGWINCH:  return 'SIGWINCH';
      case SIGPWR:    return 'SIGPWR';
      default:        throw new Exception( __CLASS__ . "::signal_string $sig not recognized");
    }

  }

  public function signal_catcher( $sig )
  {
    // print "Caught " . self::signal_string($sig) . "\n";
    switch( $sig )
    {
      case SIGHUP:
      case SIGTSTP:   // 
        return true;
        break;
      case SIGTERM:   // Shutdown
        $this->sig_term();
        break;
      case SIGCHLD:   // Halt
        $this->sig_child();
        break;
      default:
        throw new Exception( get_class($this) . "::signal_catcher " . self::signal_string($sig) . " not handled");
    }
  }

  public function cleanup()
  {
    // Do nothing.
  }
}

   
// Example usage:
/*
class DaemonTest extends Daemon {
  public function __construct()
  {
    parent::__construct();
    $this->expire = 2;
//    $this->user = 'nobody';
//    $this->group = 'nogroup';
  }
  
  function child()
  {
    print date("H:i:s") . "\n"; 
    print "Child PID: " . getmypid() . "\n";
    print "Hey Dad\n";
    // Generate some CPU time
    for( $i= 0; ; $i++);
    //sleep(3);
  }
  
}

print "Master PID: " . getmypid() . "\n";
$daemon = new DaemonTest();
$daemon->max_procs = 5;
// Fork and continue the parent process
while(true)
{
  $daemon->fork();
}

*/

/*
//Daemonize.. Fork and upon sucessful spawning of a child process exit the parent process
print "Daemonizing...\n";
$daemon->daemonize();
sleep(15);
*/

?>
