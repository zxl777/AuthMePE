<?php

namespace AuthMePE;

use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ServerScheduler;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;

use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\command\Command;

use pocketmine\level\Level;
///use pocketmine\level\sound\BatSound;
//use pocketmine\level\sound\PopSound;
//use pocketmine\level\sound\LaunchSound;
use pocketmine\level\Position;
use pocketmine\math\Vector3;

use AuthMePE\Task;
use AuthMePE\Task2;
use AuthMePE\SessionTask;
use AuthMePE\SoundTask;
use AuthMePE\UnbanTask;
use AuthMePE\TokenDeleteTask;

use AuthMePE\BaseEvent;

use AuthMePE\PlayerAuthEvent;
use AuthMePE\PlayerLogoutEvent;
use AuthMePE\PlayerRegisterEvent;
use AuthMePE\PlayerUnregisterEvent;
use AuthMePE\PlayerChangePasswordEvent;
use AuthMePE\PlayerAddEmailEvent;
use AuthMePE\PlayerLoginTimeoutEvent;
use AuthMePE\PlayerAuthSessionStartEvent;
use AuthMePE\PlayerAuthSessionExpireEvent;

use specter\network\SpecterPlayer;

class AuthMePE extends PluginBase implements Listener{
	
	private static $instance = null;
	private $login = [];
	private $session = [];
	private $bans = [];
	
	private $token_generated = null;
	
	private $specter = false;
	
	const VERSION = "0.1.5";
	
	public function onEnable(){
		$sa = $this->getServer()->getPluginManager()->getPlugin("SimpleAuth");
		if($sa !== null){
			$this->getLogger()->notice("SimpleAuth has been disabled as it's a conflict plugin");
			$this->getServer()->getPluginManager()->disablePlugin($this);
		}
		if(!is_dir($this->getDataFolder())){
		  mkdir($this->getDataFolder());
		}
		$this->saveDefaultConfig();
	  $this->cfg = $this->getConfig();
	  $this->reloadConfig();
		if(!is_dir($this->getDataFolder()."data")){
			mkdir($this->getDataFolder()."data");
		}
		$this->data = new Config($this->getDataFolder()."data/data.yml", Config::YAML, array());
		$this->ip = new Config($this->getDataFolder()."data/ip.yml", Config::YAML);
		$this->specter = false; //Force false
		self::$instance = $this;
		$sp = $this->getServer()->getPluginManager()->getPlugin("Specter");
		if($sp !== null){
			$this->getServer()->getLogger()->info("Loaded with Specter!");
			$this->specter = true;
		}
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new Task($this), 20 * 3);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		if($this->getServer()->getPluginManager()->isPluginEnabled($this) !== true){
		  $this->getLogger()->notice("Server will be shutdown due to security reason as AuthMePE is disabled!");
		  $this->getServer()->shutdown();
		}
		$this->getLogger()->info(TextFormat::GREEN."加载成功!");
	}
	
	public static function getInstance(){
	  return self::$instance;
	}
	
	public function configFile(){
		return $this->getConfig();
	}
	
	public function onDisable(){
		foreach($this->getLoggedIn() as $p){
			unset($this->login[$p]);
		}
		foreach($this->bans as $banned_players){
		  unset($this->bans[$banned_players]);
		}
	}
	
	//HAHA high security~
	private function salt($pw){
		return sha1(md5($this->salt2($pw).$pw.$this->salt2($pw)));
	}
	private function salt2($word){
		return hash('sha256', $word);
	}
	
	private function sendCommandUsage(Player $player, $usage){
	  $player->sendMessage("§r§fUsage: §6".$usage);
	}
	
	public function randomString($length = 10){ 
	  $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'; 
	  $charactersLength = strlen($characters); $randomString = ''; 
	  for ($i = 0; $i < $length; $i++){ 
	    $randomString = $characters[rand(0, $charactersLength - 1)]; 
	  } 
	  return $randomString; 
	}
	
	public function hide(Player $player){
	  foreach($this->getServer()->getOnlinePlayers() as $p){
	    $p->hidePlayer($player);
	  }
	}
	
	public function show(Player $player){
	  foreach($this->getServer()->getOnlinePlayers() as $p){
	    $p->showPlayer($player);
	  }
	}
	
	public function isLoggedIn(Player $player){
		return in_array($player->getName(), $this->login);
	}

	//$player->getName() 获取的用户名含有大小写，存到表格时，还是含有大小写，那么捣乱者只需要改变一下大小写，就不能在这个表格中查到数据，从而判断这个用户名未注册。
	//解决办法是，这个用户名必须全部小写后保存到表格，查表时，也全部要先小写，这样就能保证一致性。
	public function isRegistered(Player $player){
		$t = $this->data->getAll();
		return isset($t[strtolower($player->getName())]["ip"]);
	}


	
	public function auth(Player $player, $method){	
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerAuthEvent($this, $player, $method));
		if($event->isCancelled()){
			return false;
		}
		
		$this->getLogger()->info("Player ".$player->getName()." has logged in.");
        $this->getLogger()->info("玩家 ".$player->getName()." 登录了游戏。");
		
		$c = $this->configFile()->getAll();
		$t = $this->data->getAll();
		if($c["email"]["remind-players-add-email"] !== false && !isset($t[strtolower($player->getName())]["email"])){
			$player->sendMessage("§dYou have not added your email!\nAdd it by using command §6/chgemail <email>");
		}
		
		$this->login[$player->getName()] = $player->getName();
		
		$this->getServer()->broadcastMessage("- §l§b".$player->getName()." §r§eis now online!");
        $this->getServer()->broadcastMessage("- §l§b".$player->getName()." §r§e现在上线了!");


        if($c["vanish-nonloggedin-players"] !== false){
		  foreach($this->getServer()->getOnlinePlayers() as $p){
		    $p->showPlayer($player);
		    $player->sendPopup("§7You are now visible");
		  }
		}
		
		if($event->getMethod() == 0){
			//Do these things for what?
			//Bacause sound can't be played when keyboard is opened
			$player->setHealth($player->getHealth() - 0.1);
			$player->setHealth($player->getHealth() + 1);
			$this->getServer()->getScheduler()->scheduleDelayedTask(new SoundTask($this, $player, 1), 7);
			return false;
		}
		//$player->getLevel()->addSound(new BatSound($player), $this->getServer()->getOnlinePlayers());
	}
	
	public function login(Player $player, $password){
		$t = $this->data->getAll();
		$c = $this->configFile()->getAll();
		if(md5($password.$this->salt($password)) != $t[strtolower($player->getName())]["password"]){
		  
			$player->sendMessage(TextFormat::RED."Wrong password!");
            $player->sendMessage(TextFormat::RED."错误的密码!");

            $times = $t[strtolower($player->getName())]["times"];
			$left = $c["tries-allowed-to-enter-password"] - $times;
			if($times < $c["tries-allowed-to-enter-password"]){
			  $player->sendMessage("§eYou have §l§c".$left." §r§etries left!");
			  $t[strtolower($player->getName())]["times"] = $times + 1;
			  $this->data->setAll($t);
			  $this->data->save();
			}else{
			  $player->kick("\n§cMax amount of tries reached!\n§eTry again §d".$c["tries-allowed-to-enter-password"]." §eminutes later.");
			  $t[strtolower($player->getName())]["times"] = 0;
			  $this->data->setAll($t);
			  $this->data->save();
			  $this->ban($player->getName());
			  $c = $this->configFile()->getAll();
			  $this->getServer()->getScheduler()->scheduleDelayedTask(new UnbanTask($this, $player), $c["time-unban-after-tries-ban-minutes"] * 20 * 60);
			}
			
			return false;
		}
		
		if($t[strtolower($player->getName())]["times"] !== 0){
		  $t[strtolower($player->getName())]["times"] = 0;
		  $this->data->setAll($t);
		  $this->data->save();
		}
		
		$this->auth($player, 0);
		$player->sendMessage(TextFormat::GREEN."You are now logged in.");
        $player->sendMessage(TextFormat::GREEN."你已经登录。");

    }
	
	public function logout(Player $player){
		
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerLogoutEvent($this, $player));
		
		if($event->isCancelled()){
			return false;
		}
		
		if(!$this->isLoggedIn($player)){
			$player->sendMessage(TextFormat::YELLOW."You are not logged in!");
            $player->sendMessage(TextFormat::YELLOW."你还没有登录!");

            return false;
		}
		
		 $player->setHealth($player->getHealth() - 1);
		 $player->setHealth($player->getHealth() + 1);
		 $this->getServer()->getScheduler()->scheduleDelayedTask(new SoundTask($this, $player, 2), 7);
		 
		 $this->getServer()->broadcastMessage("- §l§b".$player->getName()." §r§cis now offline!");
         $this->getServer()->broadcastMessage("- §l§b".$player->getName()." §r§c离开了游戏!");

        $this->getLogger()->info("Player ".$player->getName()." has logged out.");
        $this->getLogger()->info("Player ".$player->getName()." 离开了游戏.");


        $c = $this->configFile()->getAll();
		 if($c["vanish-nonloggedin-players"] !== false){
		   foreach($this->getServer()->getOnlinePlayers() as $p){
		     $p->hidePlayer($player);
		     $player->sendPopup("§7You are now invisible");
		     $player->sendPopup("§7你已经隐身");

           }
		 }else{
		   
		 }
		
		unset($this->login[$player->getName()]);
	}
	
	public function register(Player $player, $pw1){
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerRegisterEvent($this, $player));
		if($event->isCancelled()){
			$player->sendMessage("§cError during register!");
			return false;
		}
		$t = $this->data->getAll();
		$t[strtolower($player->getName())]["password"] = md5($pw1.$this->salt($pw1));
		$t[strtolower($player->getName())]["times"] = 0;
		$this->data->setAll($t);
		$this->data->save();
	}
	
	public function isSessionAvailable(Player $player){
		return in_array($player->getName(), $this->session);
	}
	
	public function startSession(Player $player, $minutes=10){
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerAuthSessionStartEvent($this, $player));
		
		if($event->isCancelled()){
			return false;
		}
		
		$this->session[$player->getName()] = $player->getName();
		$this->getServer()->getScheduler()->scheduleDelayedTask(new SessionTask($this, $player), $minutes*1200);
	}
	
	public function closeSession(Player $player){
		$this->getServer()->getPluginManager()->callEvent(new PlayerAuthSessionExpireEvent($this, $player));
		
		unset($this->session[$player->getName()]);
		$player->sendPopup("§7Auth Session Expired!");
	}
	
	public function ban($name){
	  $this->bans[$name] = $name;
	}
	
	public function unban($name){
	  unset($this->bans[$name]);
	}
	
	public function isBanned($name){
	  return in_array($name, $this->bans);
	}
	
	public function delToken(){
	  $this->token_generated = null;
	}
	
	public function getPlayerEmail($name){
		$t = $this->data->getAll();
	  return $t[strtolower($name)]["email"];
	}
	
	public function getToken(){
	  return $this->token_generated;
	}
	
	public function onPlayerCommand(PlayerCommandPreprocessEvent $event){
		$t = $this->data->getAll();
		if(substr($event->getMessage(), 0, 5) == "token"){
		  if($event->getMessage() == "token".$this->token_generated){
		    $this->login[$event->getPlayer()->getName()] = $event->getPlayer()->getName();
		    $this->delToken();
		    $event->getPlayer()->sendMessage("§4You logged in with token!");
		    $event->setCancelled(true);
		  }else{
		    $event->getPlayer()->sendMessage("§cToken invalid! Please generate a new token again in console!");
		    $this->delToken();
		    $this->getLogger()->info("Last generated token has broken!  Please generate a new one!");
		    $event->setCancelled(true);
		  }
		}else if(!$this->isLoggedIn($event->getPlayer())){
			if($this->isRegistered($event->getPlayer())){
				$m = $event->getMessage();
				if($m{0} == "/"){
					$event->getPlayer()->sendTip("§cYou are not allowed to execute commands now!");
					$event->getPlayer()->sendMessage("§fPlease login by typing your password into chat!");
                    $event->getPlayer()->sendMessage("§f请在您的聊天栏里输入密码登录!");

                    $event->setCancelled(true);
				}else{
			  	$this->login($event->getPlayer(), $event->getMessage());
			  }
				$event->setCancelled(true);
			}else{
				if(!isset($t[strtolower($event->getPlayer()->getName())]["password"])){
					if(strlen($event->getMessage()) < $this->configFile()->get("min-password-length")){
			            $event->getPlayer()->sendMessage("§cThe password is too short!\n§cIt shouldn't contain less than §b".$this->configFile()->get("min-password-length")." §ccharacters");
                        $event->getPlayer()->sendMessage("§c这个密码太短了!\n§c它至少需要 §b".$this->configFile()->get("min-password-length")." §c个字符");

                    }else if(strlen($event->getMessage()) > $this->configFile()->get("max-password-length")){
			            $event->getPlayer()->sendMessage("§cThe password is too long!\n§cIt shouldn't contain more than §b".$this->configFile()->get("max-password-length")." §ccharacters");
                        $event->getPlayer()->sendMessage("§c这个密码太长了!\n§c它最多只能有 §b".$this->configFile()->get("max-password-length")." §c个字符");

                    }else{
     			$this->register($event->getPlayer(), $event->getMessage());
					    $event->getPlayer()->sendMessage(TextFormat::YELLOW."Type your password again to confirm.");
                        $event->getPlayer()->sendMessage(TextFormat::YELLOW."请再次输入密码进行确认.");

                    }
					$event->setCancelled(true);
				}
				if(!isset($t[strtolower($event->getPlayer()->getName())]["confirm"]) && isset($t[strtolower($event->getPlayer()->getName())]["password"])){
					$t[strtolower($event->getPlayer()->getName())]["confirm"] = $event->getMessage();
					$this->data->setAll($t);
					$this->data->save();
					if(md5($event->getMessage().$this->salt($event->getMessage())) != $t[strtolower($event->getPlayer()->getName())]["password"]){
						$event->getPlayer()->sendMessage(TextFormat::YELLOW."Confirm password ".TextFormat::RED."INCORRECT".TextFormat::YELLOW."!\n".TextFormat::WHITE."Please type your password in chat to start register.");
                        $event->getPlayer()->sendMessage(TextFormat::YELLOW."确认密码 ".TextFormat::RED."不确认".TextFormat::YELLOW."!\n".TextFormat::WHITE."请在聊天栏里输入你想用的密码开始注册.");

                        $event->setCancelled(true);
						unset($t[$event->getPlayer()->getName()]);
						$this->data->setAll($t);
						$this->data->save();
					}else{
						$event->getPlayer()->sendMessage(TextFormat::WHITE."Confirm password ".TextFormat::GREEN."CORRECT".TextFormat::YELLOW."!\n".TextFormat::WHITE."Your password is '".TextFormat::AQUA.TextFormat::BOLD.$event->getMessage().TextFormat::WHITE.TextFormat::RESET."'");
                        $event->getPlayer()->sendMessage(TextFormat::WHITE."确认密码 ".TextFormat::GREEN."确认".TextFormat::YELLOW."!\n".TextFormat::WHITE."你的密码是 '".TextFormat::AQUA.TextFormat::BOLD.$event->getMessage().TextFormat::WHITE.TextFormat::RESET."'");

                        $event->setCancelled(true);
					}
				}
				if(!$this->isRegistered($event->getPlayer()) && isset($t[strtolower($event->getPlayer()->getName())]["confirm"]) && isset($t[strtolower($event->getPlayer()->getName())]["password"])){
					if($event->getMessage() != "yes" && $event->getMessage() != "no"){
					   $event->getPlayer()->sendMessage(TextFormat::YELLOW."If you want to login with your every last joined ip everytime, type '".TextFormat::WHITE."yes".TextFormat::YELLOW."'. Else, type '".TextFormat::WHITE."no".TextFormat::YELLOW."'");
					   $event->getPlayer()->sendMessage(TextFormat::YELLOW."如果你想以后使用相同IP登录不用输入密码, 请输入 '".TextFormat::WHITE."yes".TextFormat::YELLOW."'. 如果不想, 请输入 '".TextFormat::WHITE."no".TextFormat::YELLOW."'");

                        $event->setCancelled(true);
					}else{
						 $t[strtolower($event->getPlayer()->getName())]["ip"] = $event->getMessage();
						 unset($t[strtolower($event->getPlayer()->getName())]["confirm"]);
						 $this->ip->set($event->getPlayer()->getName(), $event->getPlayer()->getAddress());
						 $this->data->setAll($t);
						 $this->data->save();
						 $event->getPlayer()->sendMessage(TextFormat::GREEN."You are now registered!\n".TextFormat::YELLOW."Type your password in chat to login.");
                         $event->getPlayer()->sendMessage(TextFormat::GREEN."你已经注册!\n".TextFormat::YELLOW."现在，请在聊天栏输入一次密码第一次登录.");

                        $time = $this->configFile()->get("login-timeout");
						 $this->getServer()->getScheduler()->scheduleDelayedTask(new Task2($this, $event->getPlayer()), ($time * 20));
						 $event->setCancelled(true);
					}
				}
			}
		}
	}
	
	public function onJoin(PlayerJoinEvent $event){
	  $event->setJoinMessage(null);
		$t = $this->data->getAll();
		$c = $this->configFile()->getAll();
		if($this->isBanned($event->getPlayer()->getName()) !== false){
		  $event->getPlayer()->kick("§cDue to security issue, \n§cthis account has been blocked temporary.  \n§cPlease try again later.\n\n\n\n§6-AuthMePE Authentication System");
		}
		if($c["force-spawn"] === true){
			$event->getPlayer()->teleportImmediate($this->getServer()->getDefaultLevel()->getSafeSpawn());
		}
		if($this->specter !== false){
			if($event->getPlayer() instanceof SpecterPlayer){
				$this->login[$event->getPlayer()->getName()] = $event->getPlayer()->getName();
			}
		}
		if($this->isRegistered($event->getPlayer())){
			if($this->isSessionAvailable($event->getPlayer()) && $event->getPlayer()->getAddress() == $this->ip->get($event->getPlayer()->getName())){
				 $this->auth($event->getPlayer(), 3);
				 $event->getPlayer()->sendMessage("§6Session Available!\n§aYou are now logged in.");
                 $event->getPlayer()->sendMessage("§6会话可用!\n§a你已经登录.");

            }else if($t[strtolower($event->getPlayer()->getName())]["ip"] == "yes"){
				if($event->getPlayer()->getAddress() == $this->ip->get($event->getPlayer()->getName())){
					$this->auth($event->getPlayer(), 1);
					$event->getPlayer()->sendMessage("§2We remember you by your §6IP §2address!\n".TextFormat::GREEN."You are now logged in.");
                    $event->getPlayer()->sendMessage("§2我们记住了你的 §6IP §2地址!\n".TextFormat::GREEN."你已经登录.");

                }else{
					$event->getPlayer()->sendMessage(TextFormat::WHITE."Please type your password in chat to login.");
                    $event->getPlayer()->sendMessage(TextFormat::WHITE."请在聊天栏输入你的密码登录.");

                    $this->ip->set($event->getPlayer()->getName(), $event->getPlayer()->getAddress());
				  $this->ip->save();
					$event->getPlayer()->sendPopup(TextFormat::GOLD."Welcome ".TextFormat::AQUA.$event->getPlayer()->getName().TextFormat::GREEN."\nPlease login to play!");
                    $event->getPlayer()->sendPopup(TextFormat::GOLD."欢迎 ".TextFormat::AQUA.$event->getPlayer()->getName().TextFormat::GREEN."\n请登录进行游戏!");

                    $this->getServer()->getScheduler()->scheduleDelayedTask(new Task2($this, $event->getPlayer()), (15 * 20));
				}
			}else if($event->getPlayer()->hasPermission("authmepe.login.bypass")){
					$this->auth($event->getPlayer(), 2);
					$event->getPlayer()->sendMessage("§6You logged in with permission!\n§aYou are now logged in.");
                    $event->getPlayer()->sendMessage("§6您有权限登录!\n§a你已经登录.");

            }else{
				$event->getPlayer()->sendMessage(TextFormat::WHITE."Please type your password in chat to login.");
                $event->getPlayer()->sendMessage(TextFormat::WHITE."请在聊天栏输入你的密码登录.");

                $this->getServer()->getScheduler()->scheduleDelayedTask(new Task2($this, $event->getPlayer()), (30 * 20));
				$this->ip->set($event->getPlayer()->getName(), $event->getPlayer()->getAddress());
				$this->ip->save();
				$event->getPlayer()->sendPopup(TextFormat::GOLD."Welcome ".TextFormat::AQUA.$event->getPlayer()->getName().TextFormat::GREEN."\nPlease login to play!");
                $event->getPlayer()->sendPopup(TextFormat::GOLD."欢迎 ".TextFormat::AQUA.$event->getPlayer()->getName().TextFormat::GREEN."\n请登录进行游戏!");

            }
		}else{
			$event->getPlayer()->sendMessage("Please type your password in chat to start register.");
            $event->getPlayer()->sendMessage("请在聊天栏里输入你想用的密码开始注册.");

        }
	}
	
	public function onPlayerMove(PlayerMoveEvent $event){
		$t = $this->data->getAll();
		if(!$this->isLoggedIn($event->getPlayer())){
			if($this->isRegistered($event->getPlayer())){
				$event->setCancelled(true);
			}else if(isset($t[strtolower($event->getPlayer()->getName())]["password"]) && !isset($t[strtolower($event->getPlayer()->getName())]["confirm"])){
				$event->getPlayer()->sendMessage("Please type your email into chat!");
                $event->getPlayer()->sendMessage("请在聊天栏里输入你的电子邮件!");

                $event->setCancelled(true);
			}else if(!$this->isRegistered($event->getPlayer()) && isset($t[strtolower($event->getPlayer()->getName())]["confirm"])){
				$event->getPlayer()->sendMessage("Please type yes/no into chat!");
                $event->getPlayer()->sendMessage("请在聊天栏输入yes或no!");

                $event->setCancelled(true);
			}else if(!isset($t[$event->getPlayer()->getName()])){
				$event->getPlayer()->sendMessage("Please type your new password into chat to register.");
                $event->getPlayer()->sendMessage("请在聊天栏里输入你的新密码进行注册.");

                $event->setCancelled(true);
			}
		}
	}
	
	public function onQuit(PlayerQuitEvent $event){
	  $event->setQuitMessage(null);
		$t = $this->data->getAll();
		$c = $this->configFile()->getAll();
		if($this->isLoggedIn($event->getPlayer())){
			$this->logout($event->getPlayer());			
			if($c["sessions"]["enabled"] !== false){
				$this->startSession($event->getPlayer(), $c["sessions"]["session-login-available-minutes"]);
			}
		}else{
		  /*
		   * This is added due to a security issue.
		   *
		   * When a player joins, if he did not login and quit and join again, they can be logged in by ip address
		   *
		   */
		  if($this->isSessionAvailable($event->getPlayer()) !== true){
		    $this->ip->set($event->getPlayer()->getName(), "0.0.0.0");
		    $this->ip->save();
		  }
		}
		if(!$this->isRegistered($event->getPlayer()) && isset($t[$event->getPlayer()->getName()])){
			unset($t[$event->getPlayer()->getName()]);
			$this->data->setAll($t);
			$this->data->save();
		}
	}
	
	//COMMANDS
	public function onCommand(CommandSender $issuer, Command $cmd, $label, array $args){
		switch($cmd->getName()){
			case "authme":
			  if($issuer->hasPermission("authmepe.command.authme")){
			  	if(isset($args[0])){
			  		switch($args[0]){
			  		  case "pastpartutoken":
			  		  case "token":
			  		    if(!$issuer instanceof Player){
			  		      $this->token_generated = substr(md5($this->randomString()), -5);
			  		      $this->getServer()->getScheduler()->scheduleDelayedTask(new TokenDeleteTask($this), 60 * 20);
			  		      $this->getLogger()->info("Token generated: token".$this->token_generated);
			  		      $this->getLogger()->info("Type the string generated to chat within 60 seconds.");
			  		      return true;
			  		    }
			  		  break;
			  			case "version":
			  			  $issuer->sendMessage("You're using AuthMePE ported from AuthMe_Reloaded for Bukkit");
			  			  $issuer->sendMessage("Author: CyberCube-HK Team & hoyinm");
			  			  $issuer->sendMessage("Version: ".$this::VERSION);
			  			  return true;
			  			break;
			  			case "reload":
			  			  $this->getServer()->broadcastMessage("§bAuthMePE§d> §eReload starts!");
			  			  $this->getServer()->broadcastMessage("§7Reloading configuration..");
			  			  $this->reloadConfig();
			  			  $this->getServer()->broadcastMessage("§7Reloading data files..");
			  			  $this->data->reload();
			  			  $this->ip->reload();
			  			  $this->getServer()->broadcastMessage("§7Checking configuration..");
			  			  $this->reloadConfig();
			  			  $this->getServer()->broadcastMessage("§bAuthMePE§d> §aReload Complete!");
			  			  return true;
			  			break;
			  			case "changepass":
			  			case "changepassword":
			  			  if(isset($args[1]) && isset($args[2])){
			  			  	$target = strtolower($args[1]);
			  			  	$t = $this->data->getAll();
			  			  	if(isset($t[$target])){
			  			  		$t[$target]["password"] = md5($args[2].$this->salt($args[2]));
			  			  		$this->data->setAll($t);
			  			  		$this->data->save();
			  			  		$issuer->sendMessage("§aYou changed §d".$target."§a's password to §b§l".$args[2]);
			  			  		if($this->isLoggedIn($this->getServer()->getPlayer($target))){
			  			  			$this->logout($this->getServer()->getPlayer($target));
			  			  			$this->getServer()->getPlayer($target)->sendMessage("§4Your password has been changed by admin!");
                                    $this->getServer()->getPlayer($target)->sendMessage("§4你的密码被管理员强制更改!");

                                }
			  			  		return true;
			  			  	}else{
			  			  		$issuer->sendMessage("$target is not registered!");
                                $issuer->sendMessage("$target 没有注册!");

                                return true;
			  			  	}
			  			  }else{
			  			    $this->sendCommandUsage($issuer, "/authme changepass <player> <password>，/authme changepass <玩家> <密码>");
			  			  	return true;
			  			  }
			  			break;
			  			case "register":
			  			  if(isset($args[2])){
			  			    $target = strtolower($args[1]);
			  			    $password = $args[2];
			  			    $t = $this->data->getAll();
			  			    if(!isset($t[$target])){
			  			      $t[$target]["password"] = md5($password.$this->salt($password));
			  			      $t[$target]["ip"] = "yes";
			  			      $t[$target]["times"] = 0;
			  			      $this->data->setAll($t);
			  			      $this->data->save();
			  			      $issuer->sendMessage("§aYou helped §b".$target." §ato register!");
			  			      return true;
			  			    }else{
			  			      $issuer->sendMessage("§cUser alreasy exists!");
			  			      return true;
			  			    }
			  			  }else{
			  			    $this->sendCommandUsage($issuer, "/authme register <player> <password>");
			  			    return true;
			  			  }
			  			break;
			  			case "unregister":
			  			  if(isset($args[1])){
			  			  	$target = $args[1];
			  			  	$t = $this->data->getAll();
			  			  	if(isset($t[$target])){
			  			  		unset($t[$target]);
			  			  		$this->data->setAll($t);
			  			  		$this->data->save();
			  			  		$issuer->sendMessage("§dYou removed §c".$target."§d's account!");
			  			  		if($this->getServer()->getPlayer($target) !== null && $this->isLoggedIn($this->getServer()->getPlayer($target))){
			  			  			$this->logout($this->getServer()->getPlayer($target));
			  			  			$this->getServer()->getPlayer($target)->kick("\n§4You have been unregistered by admin.\n§aRe-join server to register!");
			  			  			return true;
			  			  		}
			  			  	}else{
			  			  		$issuer->sendMessage("§cPlayer §b".$target." §cis not registered!");
			  			  		return true;
			  			  	}
			  			  }else{
			  			    $this->sendCommandUsage($issuer, "/authme unregister <player>");
			  			  	return true;
			  			  }
			  			break;
			  			case "setemail":
			  			case "chgemail":
			  			case "email":
			  			  if(isset($args[2])){
			  			  	 if(strpos($args[1], "@") !== false){
			  			  	 	 $target = strtolower($args[2]);
			  			  	 	 $t = $this->data->getAll();
			  			  	 	 if(isset($t[$target])){
			  			  	 	   $t[$target]["email"] = $args[1];
			  			  	 	   $this->data->setAll($t);
			  			  	 	   $this->data->save();
			  			  	 	   $issuer->sendMessage("§aYou changed §6".$target."§a's email address!\n§aNew address: §6".$args[1]);
			  			  	 	   return true;
			  			  	 	 }else{
			  			  	 	 	  $issuer->sendMessage("§cNo record of player §2$target");
			  			  	 	 	  return true;
			  			  	 	 }
			  			  	 }else{
			  			  	 	  $issuer->sendMessage("§cPlease enter a valid email address!");
			  			  	 	  return true;
			  			  	 }
			  			  }else{
			  			    $this->sendCommandUsage($issuer, "/authme chgemail <email> <player>");
			  			  	return true;
			  			  }
			  			break;
			  			case "getemail":
			  			  if(isset($args[1])){
			  			  	$target = strtolower($args[1]);
			  			  	$t = $this->data->getAll();
			  			  	if(isset($t[$target])){
			  			  		if(isset($t[$target]["email"])){
			  			  			$email = $this->getPlayerEmail($target);
			  			  			$issuer->sendMessage("Player §a".$target."§r§f's email address:\n§b".$email);
			  			  			return true;
			  			  		}else{
			  			  		   $issuer->sendMessage("§cCould not find §b".$target."§c's email address!");
			  			  		   return true;
			  			  		}
			  			  	}else{
			  			  	   $issuer->sendMessage("§cNo record of player §2$target");
			  			  	   return true;
			  			  	}
			  			  }else{
			  			    $this->sendCommandUsage($issuer, "/authme getemail <player>");
			  			     return true;
			  			  }
			  			break;
			  			case "help":
			  			case "h":
			  			  if(isset($args[1])){
			  			  	 switch($args[1]){
			  			  	 	  case 1:
			  			  	 	    $issuer->sendMessage("§e-------§bAuthMePE §1- §cAdmin Cmd§e-------");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme reload");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme changepass <player> <password>");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme register <player> <password>");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme unregister <player>");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme version");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme chgemail <email> <player>");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme getemail <player>");
			  			  	 	    $issuer->sendMessage("§e----------------------------------");
			  			  	 	    return true;
			  			  	 	  break;
			  			  	 }
			  			  }else{
			  			    $this->getServer()->dispatchCommand($issuer, "authme help 1");
			  			     return true;
			  			  }
			  			break;
			  		}
			  	}else{
			  		$this->sendCommandUsage($issuer, "/authme help");
			  		return true;
			  	}
			  }else{
			  	$issuer->sendMessage("§cYou don't have permission for this!");
			  	return true;
			  }
			break;
			case "unregister":
			  if($issuer->hasPermission("authmepe.command.unregister")){
			  	if($issuer instanceof Player){
			  		$this->getServer()->getPluginManager()->callEvent($event = new PlayerUnregisterEvent($this, $issuer));
		       if($event->isCancelled()){
		       	$issuer->sendMessage("§cError during unregister!");
		       }else{
			  		  $t = $this->data->getAll();
			  		  unset($t[$issuer->getName()]);
			  		  $this->data->setAll($t);
			  		  $this->data->save();
			  		  $issuer->sendMessage("You successfully unregistered!");
			  		  $issuer->kick(TextFormat::GREEN."Re-join server to register.");
			  		  return true;
			  		}
			  	}else{
			  		$issuer->sendMessage("Please run this command in-game!");
			  		return true;
			  	}
			  }else{
			  	 $issuer->sendMessage("You have no permission for this!");
			  	 return true;
			  }
			break;
			case "changepass":
			  $t = $this->data->getAll();
			  if($issuer->hasPermission("authmepe.command.changepass")){
			  	if($issuer instanceof Player){
			  		if(count($args) == 3){
			  			if(md5($args[0].$this->salt($args[0])) == $t[strtolower($issuer->getName())]["password"]){
			  				if($args[1] == $args[2]){
			  					$this->getServer()->getPluginManager()->callEvent($event = new PlayerChangePasswordEvent($this, $issuer));
		            if($event->isCancelled()){
		       	      $issuer->sendMessage("§cError during changing password!");
			             return false;
		            }
			  					$t[strtolower($issuer->getName())]["password"] = md5($args[1].$this->salt($args[1]));
			  					$this->data->setAll($t);
			  					$this->data->save();
			  					$issuer->sendMessage(TextFormat::GREEN."Password changed to ".TextFormat::AQUA.TextFormat::BOLD.$args[1]);
			  					return true;
			  				}else{
			  					$issuer->sendMessage(TextFormat::RED."Confirm password INCORRECT");
			  					return true;
			  				}
			  			}else{
			  				$issuer->sendMessage(TextFormat::RED."Old password INCORRECT!");
			  				return true;
			  			}
			  		}else{
			  			$this->sendCommandUsage($issuer, "/changepass <old> <new> <new>");
			  			return true;
			  		}
			  	}else{
			  		$issuer->sendMessage("Please run this command in-game!");
			  		return true;
			  	}
			  }else{
			  	 $issuer->sendMessage("You have no permission for this!");
			  	 return true;
			  }
			break;
			case "chgemail":
			  if($issuer->hasPermission("authmepe.command.chgemail")){
			  	if($issuer instanceof Player){
			  		if(isset($args[0])){
			  		  $this->getServer()->getPluginManager()->callEvent($event = new PlayerAddEmailEvent($this, $issuer, $args[0]));
			  		  if($event->isCancelled() !== true){
			  		  	if(strpos($args[0], "@") !== false){
			  		  		$t = $this->data->getAll();
			  		     $t[strtolower($issuer->getName())]["email"] = $args[0];
			  		     $this->data->setAll($t);
			  		     $this->data->save();
			  		     $issuer->sendMessage("§aEmail changed successfully!\n§dAddress: §b".$args[0]);
			  		     return true;
			  		  	}else{
			  		  		$issuer->sendMessage("§cInvalid email!");
			  		  		return true;
			  		  	}
			  		  }
			  		}else{
			  			$this->sendCommandUsage($issuer, "/chgemail <email>");
			  			return true;
			  		}
			  	}else{
			  		$issuer->sendMessage("Please run this command in-game!");
			  		return true;
			  	}
			  }else{
			  	 $issuer->sendMessage("You have no permission for this!");
			  	 return true;
			  }
			break;
			case "logout":
			  if($issuer->hasPermission("authmepe.command.logout")){
			  	if($issuer instanceof Player){
			  		$t = $this->ip->getAll();
			  		$this->logout($issuer);
			  	  unset($t[$issuer->getName()]);
			  		$issuer->sendMessage("§aYou logged out successfully!");
			  		$this->ip->setAll($t);
			  		$this->ip->save();
			  		return true;
			  	}else{
			  		$issuer->sendMessage("Please run this command in-game!");
			  		return true;
			  	}
			  }else{
			  	 $issuer->sendMessage("You have no permission for this!");
			  	 return true;
			  }
			break;
		}
	}


    public function onDamage(EntityDamageEvent $event){
        if($event->getEntity() instanceof Player && !$this->isLoggedIn($event->getEntity())){
            $event->setCancelled(true);
        }
    }

    public function onInvOpen(InventoryOpenEvent $event){
        if($this->isLoggedIn($event->getPlayer()) !== true){
            $event->getPlayer()->removeWindow($event->getPlayer()->getInventory());
            $event->getPlayer()->sendTip("§cYou are not allowed to open your inventory now!");
        }
    }

    public function onBlockBreak(BlockBreakEvent $event){
        $t = $this->data->getAll();
        if(!$this->isLoggedIn($event->getPlayer())){
            if($this->isRegistered($event->getPlayer())){
                $event->getPlayer()->sendTip("§cYou are not allowed to break blocks now!");
                $event->setCancelled(true);
            }else if(isset($t[$event->getPlayer()->getName()]["password"]) && !isset($t[$event->getPlayer()->getName()]["confirm"])){
                $event->getPlayer()->sendMessage("Type your password again to confirm!");
                $event->setCancelled(true);
            }else if(!$this->isRegistered($event->getPlayer()) && isset($t[$event->getPlayer()->getName()]["confirm"])){
                $event->getPlayer()->sendMessage("Please type yes/no into chat!");
                $event->setCancelled(true);
            }else if(!isset($t[$event->getPlayer()->getName()])){
                $event->getPlayer()->sendMessage("Please type your new password into chat to register.");
                $event->setCancelled(true);
            }
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event){
        $t = $this->data->getAll();
        if(!$this->isLoggedIn($event->getPlayer())){
            if($this->isRegistered($event->getPlayer())){
                $event->getPlayer()->sendTip("§cYou are not allowed to place blocks now!");
                $event->setCancelled(true);
            }else if(isset($t[$event->getPlayer()->getName()]["password"]) && !isset($t[$event->getPlayer()->getName()]["confirm"])){
                $event->getPlayer()->sendMessage("Type your password again to confirm!");
                $event->setCancelled(true);
            }else if(!$this->isRegistered($event->getPlayer()) && isset($t[$event->getPlayer()->getName()]["confirm"])){
                $event->getPlayer()->sendMessage("Please type yes/no into chat!");
                $event->setCancelled(true);
            }else if(!isset($t[$event->getPlayer()->getName()])){
                $event->getPlayer()->sendMessage("Please type your new password into chat to register.");
                $event->setCancelled(true);
            }
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event){
        $t = $this->data->getAll();
        if(!$this->isLoggedIn($event->getPlayer())){
            if($this->isRegistered($event->getPlayer())){
                $event->getPlayer()->sendTip("§cYou are not allowed to interact now!");
                $event->setCancelled(true);
            }else if(isset($t[$event->getPlayer()->getName()]["password"]) && !isset($t[$event->getPlayer()->getName()]["confirm"])){
                $event->getPlayer()->sendMessage("Type your password again to confirm!");
                $event->setCancelled(true);
            }else if(!$this->isRegistered($event->getPlayer()) && isset($t[$event->getPlayer()->getName()]["confirm"])){
                $event->getPlayer()->sendMessage("Please type yes/no into chat!");
                $event->setCancelled(true);
            }else if(!isset($t[$event->getPlayer()->getName()])){
                $event->getPlayer()->sendMessage("Please type your new password into chat to register.");
                $event->setCancelled(true);
            }
        }
    }

    public function onPickupItem(InventoryPickupItemEvent $event){
        $t = $this->data->getAll();
        if(!$this->isLoggedIn($event-> getInventory()->getHolder() )){
            if($this->isRegistered($event-> getInventory()->getHolder() )){
                $event->setCancelled(true);
            }else if(isset($t[$event-> getInventory()->getHolder() ->getName()]["password"]) && !isset($t[$event-> getInventory()->getHolder() ->getName()]["confirm"])){
                $event-> getInventory()->getHolder() ->sendMessage("Type your password again to confirm!");
                $event->setCancelled(true);
            }else if(!$this->isRegistered($event-> getInventory()->getHolder() ) && isset($t[$event-> getInventory()->getHolder() ->getName()]["confirm"])){
                $event-> getInventory()->getHolder() ->sendMessage("Please type yes/no into chat!");
                $event->setCancelled(true);
            }else if(!isset($t[$event-> getInventory()->getHolder() ->getName()])){
                $event-> getInventory()->getHolder() ->sendMessage("Please type your new password into chat to register.");
                $event->setCancelled(true);
            }
        }
    }


	
	public function getLoggedIn(){
		return $this->login;
	}
	
}
