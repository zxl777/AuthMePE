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
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;

use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\command\Command;

use pocketmine\level\Level;
use pocketmine\level\sound\BatSound;
use pocketmine\level\sound\PopSound;
use pocketmine\level\sound\LaunchSound;
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
	
	private $login = array();
	private $session = array();
	private $bans = array();
	
	private $token_generated = null;
	
	private $specter = false;
	
	const VERSION = "0.1.4";
	
	public function getInstance(){
	  return $this;
	}



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

        $this->username = new Config($this->getDataFolder()."data/username.yml", Config::YAML);

		$this->specter = false; //Force false
		$sp = $this->getServer()->getPluginManager()->getPlugin("Specter");
		if($sp !== null){
			$this->getServer()->getLogger()->info("Loaded with Specter!");
			$this->specter = true;
		}
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new Task($this), 20 * 3);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		if($this->getServer()->getPluginManager()->isPluginEnabled($this) !== true){
		  $this->getLogger()->notice("");
		  $this->getServer()->shutdown();
		}
		$this->getLogger()->info(TextFormat::GREEN."
		############
		####SAuth####
		##Made by lyj##
		############");
	}
	
	public function configFile(){
		return $this->getConfig();
	}
	
	public function onDisable(){
		foreach($this->getLoggedIn() as $p){
			$this->logout($this->getServer()->getPlayer($p));
		}
		foreach($this->bans as $banned_players){
		  $this->unban($banned_players);
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
	
	public function isRegistered(Player $player){
//		$t = $this->data->getAll();
		//$t[strtolower($player->getName())]["name"] = 1;
//		return isset($t[strtolower($player->getName())]["name"]);
//        return isset($t[$player->getName()]["ip"]);
//        $this->username->set(strtolower($player->getName()), "tm");

        if ($this->username->get(strtolower($player->getName())) == "tm")
        	return true;
        else
        	return false;
	}
	
	public function auth(Player $player, $method){	
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerAuthEvent($this, $player, $method));
		if($event->isCancelled()){
			return false;
		}
		
		$this->getLogger()->info("玩家 ".$player->getName()." 已经登陆游戏.");
		
		$c = $this->configFile()->getAll();
		$t = $this->data->getAll();
		if($c["email"]["remind-players-add-email"] !== false && !isset($t[$player->getName()]["email"])){
			$player->sendMessage("§d你还没有添加你的邮箱  请输入 §6/email <email> 来注册邮箱\n§b邮箱格式为:   QQ号@qq.com");
		}
		
		$this->login[$player->getName()] = $player->getName();
		
		$this->getServer()->broadcastMessage("- §o§b".$player->getName()." §r§e成功登入了游戏");
		
		if($c["vanish-nonloggedin-players"] !== false){
		  foreach($this->getServer()->getOnlinePlayers() as $p){
		    $p->showPlayer($player);
		    $player->sendPopup("§7隐身取消....");
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
		$player->getLevel()->addSound(new BatSound($player), $this->getServer()->getOnlinePlayers());
	}
	
	public function login(Player $player, $password){
		$t = $this->data->getAll();
		$c = $this->configFile()->getAll();
		if(md5($password.$this->salt($password)) != $t[$player->getName()]["password"]){
		  
			$player->sendMessage(TextFormat::RED."错误的密码!");
			$times = $t[$player->getName()]["times"];
			$left = $c["tries-allowed-to-enter-password"] - $times;
			if($times < $c["tries-allowed-to-enter-password"]){
			  $player->sendMessage("§e你还有§c".$left." §r§e次机会尝试");
			  $t[$player->getName()]["times"] = $times + 1;
			  $this->data->setAll($t);
			  $this->data->save();
			}else{
			  $player->kick("\n§c尝试太多次输入密码\n§e请在 §d".$c["tries-allowed-to-enter-password"]." §e分钟后尝试\n\n\n§e本次未成功登入记录文件中.添加ip查询.防止盗号.");
			  $t[$player->getName()]["times"] = 0;
			  $this->data->setAll($t);
			  $this->data->save();
			  $this->ban($player->getName());
			  $c = $this->configFile()->getAll();
			  $this->getServer()->getScheduler()->scheduleDelayedTask(new UnbanTask($this, $player), $c["time-unban-after-tries-ban-minutes"] * 20 * 60);
			}
			
			return false;
		}
		
		if($t[$player->getName()]["times"] !== 0){
		  $t[$player->getName()]["times"] = 0;
		  $this->data->setAll($t);
		  $this->data->save();
		}
		
		$this->auth($player, 0);

		$player->sendMessage(TextFormat::GREEN."你成功登入游戏.");

        $this->saveUsername($player);
	}

    //登录成功后，保存一个小写的名字库
    public function saveUsername(Player $player)
    {
        $this->username->set(strtolower($player->getName()), "tm");
        $this->username->save();
    }

	public function logout(Player $player){
		
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerLogoutEvent($this, $player));
		
		if($event->isCancelled()){
			return false;
		}
		
		if(!$this->isLoggedIn($player)){
			$player->sendMessage(TextFormat::YELLOW."你还没有登入");
			return false;
		}
		
		 $player->setHealth($player->getHealth() - 1);
		 $player->setHealth($player->getHealth() + 1);
		 $this->getServer()->getScheduler()->scheduleDelayedTask(new SoundTask($this, $player, 2), 7);
		 
		 $this->getServer()->broadcastMessage("§e§o- §a".$player->getName()."§e离开了服务器");
		 
		 $this->getLogger()->info("Player ".$player->getName()." 退出了游戏.");
		 
		 $c = $this->configFile()->getAll();
		 if($c["vanish-nonloggedin-players"] !== false){
		   foreach($this->getServer()->getOnlinePlayers() as $p){
		     $p->hidePlayer($player);
		     $player->sendPopup("§7§o你现在隐身中");
		   }
		 }else{
		   
		 }
		
		unset($this->login[$player->getName()]);
	}
	
	public function register(Player $player, $pw1){
		$this->getServer()->getPluginManager()->callEvent($event = new PlayerRegisterEvent($this, $player));
		if($event->isCancelled()){
			$player->sendMessage("§c§o注册错误");
			return false;
		}
		$t = $this->data->getAll();
		$t[$player->getName()]["password"] = md5($pw1.$this->salt($pw1));
		$t[$player->getName()]["times"] = 0;

		$this->data->setAll($t);
		$this->data->save();

        $this->saveUsername($player);
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
		$player->sendPopup("§7身份验证已经过期!");
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
	  return $t[$name]["email"];
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
		    $event->getPlayer()->sendMessage("§4你用了登录令牌登入!");
		    $event->setCancelled(true);
		  }else{
		    $event->getPlayer()->sendMessage("§c令牌错误.请在控制台创建新的令牌");
		    $this->delToken();
		    $this->getLogger()->info("上个令牌已经失效.请在控制台新创建");
		    $event->setCancelled(true);
		  }
		}else if(!$this->isLoggedIn($event->getPlayer())){
			if($this->isRegistered($event->getPlayer())){
				$m = $event->getMessage();
				if($m{0} == "/"){
					$event->getPlayer()->sendTip("§c你现在不允许输入任何命令!");
					$event->getPlayer()->sendMessage("§b***请输入你的密码在聊天界面");
					$event->setCancelled(true);
				}else{
			  	$this->login($event->getPlayer(), $event->getMessage());
			  }
				$event->setCancelled(true);
			}else{
				if(!isset($t[$event->getPlayer()->getName()]["password"])){
					if(strlen($event->getMessage()) < $this->configFile()->get("min-password-length")){
			     $event->getPlayer()->sendMessage("§c密码太短了!\n§c必须大于 §b".$this->configFile()->get("min-password-length")." §c个字");
			    }else if(strlen($event->getMessage()) > $this->configFile()->get("max-password-length")){
			      $event->getPlayer()->sendMessage("§c密码太长了!\n§c必须小于§b".$this->configFile()->get("max-password-length")." §c个字");
			    }else{
     			$this->register($event->getPlayer(), $event->getMessage());
					  $event->getPlayer()->sendMessage(TextFormat::YELLOW."■请在输入一遍密码来确定.");
     		}
					$event->setCancelled(true);
				}
				if(!isset($t[$event->getPlayer()->getName()]["confirm"]) && isset($t[$event->getPlayer()->getName()]["password"])){
					$t[$event->getPlayer()->getName()]["confirm"] = $event->getMessage();
					$this->data->setAll($t);
					$this->data->save();
					if(md5($event->getMessage().$this->salt($event->getMessage())) != $t[$event->getPlayer()->getName()]["password"]){
						$event->getPlayer()->sendMessage(TextFormat::YELLOW."■这个密码与上个密码 ".TextFormat::RED."不符合".TextFormat::YELLOW."!\n".TextFormat::WHITE."——请重新注册——.");
						$event->setCancelled(true);
						unset($t[$event->getPlayer()->getName()]);
						$this->data->setAll($t);
						$this->data->save();
					}else{
						$event->getPlayer()->sendMessage(TextFormat::WHITE."§b成功创建用户 §a".$event->getPlayer()->getName()."!\n".TextFormat::WHITE."§b你的密码是".TextFormat::AQUA.$event->getMessage()."\n§9∞∞∞∞∞∞∞∞∞∞\n∞∞∞∞∞∞∞∞∞∞");
						$event->setCancelled(true);
					}
				}
				if(!$this->isRegistered($event->getPlayer()) && isset($t[$event->getPlayer()->getName()]["confirm"]) && isset($t[$event->getPlayer()->getName()]["password"])){
					if($event->getMessage() != "yes" && $event->getMessage() != "no"){
					   $event->getPlayer()->sendMessage("§b如果想以后登录不用输入密码.\n§e请直接输入§byes§e或者§fno§e来选择");
					   $event->setCancelled(true);
					}else{
						 $t[$event->getPlayer()->getName()]["ip"] = $event->getMessage();
						 unset($t[$event->getPlayer()->getName()]["confirm"]);
						 $this->ip->set($event->getPlayer()->getName(), $event->getPlayer()->getAddress());
						 $this->data->setAll($t);
						 $this->data->save();
						 $event->getPlayer()->sendMessage(TextFormat::GREEN."***成功注册!\n\n\n".TextFormat::YELLOW."∞∞∞∞∞∞∞∞∞∞\n请输入您的密码登录.");
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
		  $event->getPlayer()->kick("§c太多尝试\n踢出游戏\n请在几分钟后尝试");
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
				 $event->getPlayer()->sendMessage("§6成功自动登录.");
			}else if($t[$event->getPlayer()->getName()]["ip"] == "yes"){
				if($event->getPlayer()->getAddress() == $this->ip->get($event->getPlayer()->getName())){
					$this->auth($event->getPlayer(), 1);
					$event->getPlayer()->sendMessage("§a同手机登录\n§b已经为你自动登录.");
				}else{
					$event->getPlayer()->sendMessage(TextFormat::RED."请在聊天界面输入你的密码.");
					$this->ip->set($event->getPlayer()->getName(), $event->getPlayer()->getAddress());
				  $this->ip->save();
					$event->getPlayer()->sendPopup(TextFormat::GOLD."欢迎你".TextFormat::AQUA.$event->getPlayer()->getName().TextFormat::GREEN."\n请输入密码登陆游戏!");
					$this->getServer()->getScheduler()->scheduleDelayedTask(new Task2($this, $event->getPlayer()), (15 * 20));
				}
			}else if($event->getPlayer()->hasPermission("authmepe.login.bypass")){
					$this->auth($event->getPlayer(), 2);
					$event->getPlayer()->sendMessage("§6你使用了权限登录!\n§a你现在成功登录游戏.");
			}else{
				$event->getPlayer()->sendMessage(TextFormat::WHITE."请再聊天界面输入你的密码.");
				$this->getServer()->getScheduler()->scheduleDelayedTask(new Task2($this, $event->getPlayer()), (30 * 20));
				$this->ip->set($event->getPlayer()->getName(), $event->getPlayer()->getAddress());
				$this->ip->save();
				$event->getPlayer()->sendPopup(TextFormat::GOLD."§a欢迎加入此服务器\n§b祝你游戏愉快");
			}
		}else{
			$event->getPlayer()->sendMessage("§b—■—■—■—登录系统开启—■—■—■—");
		}
	}
	
	  public function onDrop(PlayerDropItemEvent $event){
		if(!$this->isLoggedIn($event->getPlayer())){
      $event->setCancelled(true);
      $event->getPlayer()->sendTip("§c没登录之前不允许做这个动作\n§b你的背包以被锁定");
    }
  }
  
	public function onPlayerMove(PlayerMoveEvent $event){
		$t = $this->data->getAll();
		if(!$this->isLoggedIn($event->getPlayer())){
			if($this->isRegistered($event->getPlayer())){
				$event->setCancelled(true);
			}else if(isset($t[$event->getPlayer()->getName()]["password"]) && !isset($t[$event->getPlayer()->getName()]["confirm"])){
				$event->getPlayer()->sendMessage("§o§4*请输入第二次密码!");
				$event->setCancelled(true);
			}else if(!$this->isRegistered($event->getPlayer()) && isset($t[$event->getPlayer()->getName()]["confirm"])){
				$event->getPlayer()->sendMessage("§e现在请直接输入yes或者no来确定同ip免登录");
				$event->setCancelled(true);
			}else if(!isset($t[$event->getPlayer()->getName()])){
				$event->getPlayer()->sendMessage("§a***你还未注册\n§b***请直接输入密码");
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
			  			  $issuer->sendMessage("§aSAuth §bis made by §c§oRisr");
			  			  $issuer->sendMessage("§9§oThank you for use it.");
			  			  $issuer->sendMessage("Version: ".$this::VERSION);
			  			  return true;
			  			break;
			  			case "reload":
			  			  $this->getServer()->broadcastMessage("§bSAuth§d> §e开始重启!");
			  			  $this->getServer()->broadcastMessage("§7重启配置文件中.....");
			  			  $this->reloadConfig();
			  			  $this->getServer()->broadcastMessage("§7重启文件中....");
			  			  $this->data->reload();
			  			  $this->ip->reload();
			  			  $this->reloadConfig();
			  			  $this->getServer()->broadcastMessage("§bSAuth§d> §a重启完成!");
			  			  return true;
			  			break;
			  			case "changepass":
			  			case "changepassword":
			  			  if(isset($args[1]) && isset($args[2])){
			  			  	$target = $args[1];
			  			  	$t = $this->data->getAll();
			  			  	if(isset($t[$target])){
			  			  		$t[$target]["password"] = md5($args[2].$this->salt($args[2]));
			  			  		$this->data->setAll($t);
			  			  		$this->data->save();
			  			  		$issuer->sendMessage("§a你将 §d".$target."§a'的密码改为 §b".$args[2]);
			  			  		if($this->isLoggedIn($this->getServer()->getPlayer($target))){
			  			  			$this->logout($this->getServer()->getPlayer($target));
			  			  			$this->getServer()->getPlayer($target)->sendMessage("§4你的密码以被管理员更改!");
			  			  		}
			  			  		return true;
			  			  	}else{
			  			  		$issuer->sendMessage("$target 还没有注册!");
			  			  		return true;
			  			  	}
			  			  }else{
			  			    $this->sendCommandUsage($issuer, "/authme changepass <player> <password>");
			  			  	return true;
			  			  }
			  			break;
			  			case "register":
			  			  if(isset($args[2])){
			  			    $target = $args[1];
			  			    $password = $args[2];
			  			    $t = $this->data->getAll();
			  			    if(!isset($t[$target])){
			  			      $t[$target]["password"] = md5($password.$this->salt($password));
			  			      $t[$target]["ip"] = "yes";
			  			      $t[$target]["times"] = 0;
			  			      $this->data->setAll($t);
			  			      $this->data->save();
			  			      $issuer->sendMessage("§a你帮助了 §b".$target." §a完成了注册!");
			  			      return true;
			  			    }else{
			  			      $issuer->sendMessage("§c该账户已经注册!");
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
			  			  		$issuer->sendMessage("§d你成功移除 §c".$target."§d'的密码");
			  			  		if($this->getServer()->getPlayer($target) !== null && $this->isLoggedIn($this->getServer()->getPlayer($target))){
			  			  			$this->logout($this->getServer()->getPlayer($target));
			  			  			$this->getServer()->getPlayer($target)->kick("§4管理员以将你的密码重置.\n§a请重新加入服务器完成注册");
			  			  			return true;
			  			  		}
			  			  	}else{
			  			  		$issuer->sendMessage("§c玩家 §b".$target." §c还没有注册!");
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
			  			  	 	 $target = $args[2];
			  			  	 	 $t = $this->data->getAll();
			  			  	 	 if(isset($t[$target])){
			  			  	 	   $t[$target]["email"] = $args[1];
			  			  	 	   $this->data->setAll($t);
			  			  	 	   $this->data->save();
			  			  	 	   $issuer->sendMessage("§a你成功更改了 §6".$target."§a的邮箱!\n§a新的邮箱: §6".$args[1]);
			  			  	 	   return true;
			  			  	 	 }else{
			  			  	 	 	  $issuer->sendMessage("§c没有 §2$target  这个玩家");
			  			  	 	 	  return true;
			  			  	 	 }
			  			  	 }else{
			  			  	 	  $issuer->sendMessage("§c请输入正确的邮箱!");
			  			  	 	  return true;
			  			  	 }
			  			  }else{
			  			    $this->sendCommandUsage($issuer, "/authme chgemail <email> <player>");
			  			  	return true;
			  			  }
			  			break;
			  			case "getemail":
			  			  if(isset($args[1])){
			  			  	$target = $args[1];
			  			  	$t = $this->data->getAll();
			  			  	if(isset($t[$target])){
			  			  		if(isset($t[$target]["email"])){
			  			  			$email = $this->getPlayerEmail($target);
			  			  			$issuer->sendMessage("玩家 §a".$target."§r§f'的邮箱地址:\n§b".$email);
			  			  			return true;
			  			  		}else{
			  			  		   $issuer->sendMessage("§c 不能找到§b".$target."§c的邮箱地址!");
			  			  		   return true;
			  			  		}
			  			  	}else{
			  			  	   $issuer->sendMessage("§c没有 §2$target  §c这个用户");
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
			  			  	 	    $issuer->sendMessage("§e-------§b登录管理 §1- §c管理员命令§e-------");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme reload——重启插件");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme changepass <player> <password>——强制更改玩家密码");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme register <player> <password>——一键注册ID(也可改密码) ");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme unregister <player>——清除玩家的密码");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme version ——版本信息");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme chgemail <email> <player>——帮他人注册邮箱");
			  			  	 	    $this->sendCommandUsage($issuer, "/authme getemail <player>——获取他人的邮箱");
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
		       	$issuer->sendMessage("§c控制台弄毛线!");
		       }else{
			  		  $t = $this->data->getAll();
			  		  unset($t[$issuer->getName()]);
			  		  $this->data->setAll($t);
			  		  $this->data->save();
			  		  $issuer->sendMessage("你成功的清除密码");
			  		  $issuer->kick(TextFormat::GREEN."请重新加入服务器.");
			  		  return true;
			  		}
			  	}else{
			  		$issuer->sendMessage("请在游戏中输入这个指令");
			  		return true;
			  	}
			  }else{
			  	 $issuer->sendMessage("你没有权限使用这个!");
			  	 return true;
			  }
			break;
			case "changepass":
			  $t = $this->data->getAll();
			  if($issuer->hasPermission("authmepe.command.changepass")){
			  	if($issuer instanceof Player){
			  		if(count($args) == 3){
			  			if(md5($args[0].$this->salt($args[0])) == $t[$issuer->getName()]["password"]){
			  				if($args[1] == $args[2]){
			  					$this->getServer()->getPluginManager()->callEvent($event = new PlayerChangePasswordEvent($this, $issuer));
		            if($event->isCancelled()){
		       	      $issuer->sendMessage("§c控制台逗我？");
			             return false;
		            }
			  					$t[$issuer->getName()]["password"] = md5($args[1].$this->salt($args[1]));
			  					$this->data->setAll($t);
			  					$this->data->save();
			  					$issuer->sendMessage(TextFormat::GREEN."密码成功改为 ".TextFormat::AQUA.TextFormat::BOLD.$args[1]);
			  					return true;
			  				}else{
			  					$issuer->sendMessage(TextFormat::RED."与上次密码不同.请重新注册");
			  					return true;
			  				}
			  			}else{
			  				$issuer->sendMessage(TextFormat::RED."旧的密码错误");
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
			  		     $t[$issuer->getName()]["email"] = $args[0];
			  		     $this->data->setAll($t);
			  		     $this->data->save();
			  		     $issuer->sendMessage("§a成功添加邮箱\n§d邮箱为: §b".$args[0]);
			  		     return true;
			  		  	}else{
			  		  		$issuer->sendMessage("§c***错误的邮箱!");
			  		  		return true;
			  		  	}
			  		  }
			  		}else{
			  			$this->sendCommandUsage($issuer, "/email <email>");
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
			  		$issuer->sendMessage("§a成功退出登录状态!");
			  		$this->ip->setAll($t);
			  		$this->ip->save();
			  		return true;
			  	}else{
			  		$issuer->sendMessage("请在游戏中使用!");
			  		return true;
			  	}
			  }else{
			  	 $issuer->sendMessage("你没有权限使用这个!");
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
	
	public function onBlockBreak(BlockBreakEvent $event){
		 $t = $this->data->getAll();
		if(!$this->isLoggedIn($event->getPlayer())){
			if($this->isRegistered($event->getPlayer())){
			  $event->getPlayer()->sendTip("§c没登录前不允许破坏方块");
				$event->setCancelled(true);
			}else if(isset($t[$event->getPlayer()->getName()]["password"]) && !isset($t[$event->getPlayer()->getName()]["confirm"])){
				$event->getPlayer()->sendMessage("§b请在输入一遍确定密码");
				$event->setCancelled(true);
			}else if(!$this->isRegistered($event->getPlayer()) && isset($t[$event->getPlayer()->getName()]["confirm"])){
				$event->getPlayer()->sendMessage("§b请直接输入yes或者no选择同IP免登录!");
				$event->setCancelled(true);
			}else if(!isset($t[$event->getPlayer()->getName()])){
				$event->getPlayer()->sendMessage("§b没注册前禁止破坏方块.");
				$event->setCancelled(true);
			}
		}
	}
	
	public function onBlockPlace(BlockPlaceEvent $event){
		 $t = $this->data->getAll();
		if(!$this->isLoggedIn($event->getPlayer())){
			if($this->isRegistered($event->getPlayer())){
			  $event->getPlayer()->sendTip("§c你现在无法放置方块!");
				$event->setCancelled(true);
			}else if(isset($t[$event->getPlayer()->getName()]["password"]) && !isset($t[$event->getPlayer()->getName()]["confirm"])){
				$event->getPlayer()->sendMessage("§e请在输入一次!");
				$event->setCancelled(true);
			}else if(!$this->isRegistered($event->getPlayer()) && isset($t[$event->getPlayer()->getName()]["confirm"])){
				$event->getPlayer()->sendMessage("§b请直接输入§ayes§b或者§ano§b来选择同§cIP§b免登录");
				$event->setCancelled(true);
			}else if(!isset($t[$event->getPlayer()->getName()])){
				$event->getPlayer()->sendMessage("§b检测到你还没有注册 请直接输入密码进行注册.");
				$event->setCancelled(true);
			}
		}
	}
	
	public function onPlayerInteract(PlayerInteractEvent $event){
		 $t = $this->data->getAll();
		if(!$this->isLoggedIn($event->getPlayer())){
			if($this->isRegistered($event->getPlayer())){
			  $event->getPlayer()->sendTip("§c你现在不允许做任何动作");
				$event->setCancelled(true);
			}else if(isset($t[$event->getPlayer()->getName()]["password"]) && !isset($t[$event->getPlayer()->getName()]["confirm"])){
				$event->getPlayer()->sendMessage("§d请在输入一遍你的密码来确定");
				$event->setCancelled(true);
			}else if(!$this->isRegistered($event->getPlayer()) && isset($t[$event->getPlayer()->getName()]["confirm"])){
				$event->getPlayer()->sendMessage("§e请直接输入yes或者no来确定");
				$event->setCancelled(true);
			}else if(!isset($t[$event->getPlayer()->getName()])){
				$event->getPlayer()->sendMessage("§b没有注册之前不允许做任何动作.");
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
				$event-> getInventory()->getHolder() ->sendMessage("§a请在输入一遍密码来确定..");
				$event->setCancelled(true);
			}else if(!$this->isRegistered($event-> getInventory()->getHolder() ) && isset($t[$event-> getInventory()->getHolder() ->getName()]["confirm"])){
				$event-> getInventory()->getHolder() ->sendMessage("§b请直接输入yes或者no来确定");
				$event->setCancelled(true);
			}else if(!isset($t[$event-> getInventory()->getHolder() ->getName()])){
				$event-> getInventory()->getHolder() ->sendMessage("§e请在聊天界面输入你的新密码...");
				$event->setCancelled(true);
			}
		}
	}
	
	public function getLoggedIn(){
		return $this->login;
	}
	
}

namespace AuthMePE;

use pocketmine\event\plugin\PluginEvent;
use AuthMePE\AuthMePE;

abstract class BaseEvent extends PluginEvent{
	
	public function __construct(AuthMePE $plugin){
		$this->plugin = $plugin;
		parent::__construct($plugin);
	}
}

namespace AuthMePE;

use pocketmine\Player;
use pocketmine\event\Cancellable;

use AuthMePE\AuthMePE;
use AuthMePE\BaseEvent;

class PlayerAuthEvent extends BaseEvent implements Cancellable{
	public static $handlerList = null;
	
	private $player;
	private $method;
	
	const PASSWORD = 0;
	const IP = 1;
	const PERMISSION = 2;
	const SESSION = 3;
	const COMMAND = 4;
	
	public function __construct(AuthMePE $plugin, Player $player, $method){
		$this->player = $player;
		$this->method = $method;
		parent::__construct($plugin);
	}
	
	public function getPlayer(){
		return $this->player;
	}
	
	public function getMethod(){
		return $this->method;
	}
	
	public function getIp(){
		return $this->player->getAddress();
	}
}

namespace AuthMePE;

use pocketmine\event\Cancellable;
use pocketmine\Player;

use AuthMePE\AuthMePE;
use AuthMePE\BaseEvent;

class PlayerLogoutEvent extends BaseEvent implements Cancellable{
	public static $handlerList = null;
	
	private $player;
	
	public function __construct(AuthMePE $plugin, Player $player){
		$this->player = $player;
		parent::__construct($plugin);
	}
	
	public function getPlayer(){
		return $this->player;
	}
}

namespace AuthMePE;

use pocketmine\Player;
use pocketmine\event\Cancellable;

use AuthMePE\AuthMePE;
use AuthMePE\BaseEvent;

class PlayerRegisterEvent extends BaseEvent implements Cancellable{
	public static $handlerList = null;
	
	private $player;
	
	public function __construct(AuthMePE $plugin, Player $player){
		$this->player = $player;
		parent::__construct($plugin);
	}
	
	public function getPlayer(){
		return $this->player;
	}
}

namespace AuthMePE;

use pocketmine\Player;
use pocketmine\event\Cancellable;

use AuthMePE\AuthMePE;
use AuthMePE\BaseEvent;

class PlayerUnregisterEvent extends BaseEvent implements Cancellable{
	public static $handlerList = null;
	
	private $player;
	
	public function __construct(AuthMePE $plugin, Player $player){
		$this->player = $player;
		parent::__construct($plugin);
	}
	
	public function getPlayer(){
		return $this->player;
	}
}

namespace AuthMePE;

use pocketmine\Player;
use pocketmine\event\Cancellable;

use AuthMePE\AuthMePE;
use AuthMePE\BaseEvent;

class PlayerChangePasswordEvent extends BaseEvent implements Cancellable{
	public static $handlerList = null;
	
	private $player;
	
	public function __construct(AuthMePE $plugin, Player $player){
		$this->player = $player;
		parent::__construct($plugin);
	}
	
	public function getPlayer(){
		return $this->player;
	}
}

namespace AuthMePE;

use pocketmine\Player;
use pocketmine\event\Cancellable;

use AuthMePE\AuthMePE;
use AuthMePE\BaseEvent;

class PlayerAddEmailEvent extends BaseEvent implements Cancellable{
	public static $handlerList = null;
	
	private $player;
	
	public function __construct(AuthMePE $plugin, Player $player, $email){
		$this->player = $player;
		$this->email = $email;
		parent::__construct($plugin);
	}
	
	public function getPlayer(){
		return $this->player;
	}
	
	public function getEmail(){
		return $this->email;
	}
}

namespace AuthMePE;

use pocketmine\Player;

use AuthMePE\AuthMePE;
use AuthMePE\BaseEvent;

class PlayerLoginTimeoutEvent extends BaseEvent{
	public static $handlerList = null;
	
	private $player;
	
	public function __construct(AuthMePE $plugin, Player $player){
		$this->player = $player;
		parent::__construct($plugin);
	}
	
	public function getPlayer(){
		return $this->player;
	}
}

namespace AuthMePE;

use pocketmine\Player;
use pocketmine\event\Cancellable;

use AuthMePE\AuthMePE;
use AuthMePE\BaseEvent;

class PlayerAuthSessionStartEvent extends BaseEvent implements Cancellable{
	public static $handlerList = null;
	
	private $player;
	
	public function __construct(AuthMePE $plugin, Player $player){
		$this->player = $player;
		parent::__construct($plugin);
	}
	
	public function getPlayer(){
		return $this->player;
	}
}

namespace AuthMePE;

use pocketmine\Player;

use AuthMePE\AuthMePE;
use AuthMePE\BaseEvent;

class PlayerAuthSessionExpireEvent extends BaseEvent{
	public static $handlerList = null;
	
	private $player;
	
	public function __construct(AuthMePE $plugin, Player $player){
		$this->player = $player;
		parent::__construct($plugin);
	}
	
	public function getPlayer(){
		return $this->player;
	}
}

namespace AuthMePE;

use pocketmine\scheduler\PluginTask;
use AuthMePE\AuthMePE;
use pocketmine\utils\TextFormat;
use pocketmine\level\sound\LaunchSound;

class Task extends PluginTask{
	public $plugin;
	
	public function __construct(AuthMePE $plugin){
		$this->plugin = $plugin;
		parent::__construct($plugin);
	}
	
	public function onRun($tick){
		foreach($this->plugin->getServer()->getOnlinePlayers() as $p){
			if($this->plugin->isRegistered($p) && !$this->plugin->isLoggedIn($p)){
				$p->sendPopup(TextFormat::GOLD."欢迎你 ".TextFormat::AQUA.$p->getName().TextFormat::GREEN."\n请先登入在进行游戏!");
				$p->getLevel()->addSound(new LaunchSound($p), $this->plugin->getServer()->getOnlinePlayers());
			}
		}
	}
}

namespace AuthMePE;

use pocketmine\scheduler\PluginTask;
use pocketmine\utils\TextFormat;

use AuthMePE\AuthMePE;
use AuthMePE\PlayerLoginTimeoutEvent;

class Task2 extends PluginTask{
	public $plugin;
	
	public function __construct(AuthMePE $plugin, $player){
		$this->plugin = $plugin;
		$this->player = $player;
		parent::__construct($plugin);
	}
	
	public function onRun($tick){
			if(!$this->plugin->isLoggedIn($this->player) || !$this->plugin->isRegistered($this->player)){
				$this->plugin->getServer()->getPluginManager()->callEvent(new PlayerLoginTimeoutEvent($this->plugin, $this->player));
				$this->player->kick(TextFormat::RED."过长时间没有登陆§b自动踢出");
				$this->plugin->getServer()->getScheduler()->cancelTask($this->getTaskId());
			}
	}
}

namespace AuthMePE;

use pocketmine\scheduler\PluginTask;
use pocketmine\Player;

use AuthMePE\AuthMePE;

class SessionTask extends PluginTask{
	public $plugin;
	public $player;
	
	public function __construct(AuthMePE $plugin, $player){
		$this->plugin = $plugin;
		$this->player = $player;
		parent::__construct($plugin);
	}
	
	public function onRun($tick){
		$this->plugin->closeSession($this->player);
		$this->plugin->getServer()->getScheduler()->cancelTask($this->getTaskId());
	}
}

namespace AuthMePE;

use pocketmine\scheduler\PluginTask;
use pocketmine\Player;
use pocketmine\level\sound\BatSound;
use pocketmine\level\sound\LaunchSound;

use AuthMePE\AuthMePE;

class SoundTask extends PluginTask{
	public $plugin;
	public $player;
	public $type;
	
	public function __construct(AuthMePE $plugin, $player, $type){
		$this->plugin = $plugin;
		$this->player = $player;
		$this->type = $type;
		parent::__construct($plugin);
	}
	
	public function onRun($tick){
		switch($this->type){
			case 1:
			  $this->player->getLevel()->addSound(new BatSound($this->player), $this->player->getServer()->getOnlinePlayers());
			break;
			case 2:
			  $this->player->getLevel()->addSound(new LaunchSound($this->player), $this->player->getServer()->getOnlinePlayers());
			break;
		}
	}
}

namespace AuthMePE;

use pocketmine\scheduler\PluginTask;
use pocketmine\Player;

use AuthMePE\AuthMePE;

class UnbanTask extends PluginTask{
	public $plugin;
	public $player;
	
	public function __construct(AuthMePE $plugin, $player){
		$this->plugin = $plugin;
		$this->player = $player;
		parent::__construct($plugin);
	}
	
	public function onRun($tick){
		$this->plugin->unban($this->player->getName());
		$this->plugin->getServer()->getScheduler()->cancelTask($this->getTaskId());
	}
}

namespace AuthMePE;

use pocketmine\scheduler\PluginTask;

use AuthMePE\AuthMePE;

class TokenDeleteTask extends PluginTask{
	public $plugin;
	
	public function __construct(AuthMePE $plugin){
		$this->plugin = $plugin;
		parent::__construct($plugin);
	}
	
	public function onRun($tick){
		if($this->plugin->getToken() !== null){
		  $this->plugin->delToken();
		  $this->plugin->getLogger()->info("Last generated token has expired!  Please generate a new one!");
		}
		$this->plugin->getServer()->getScheduler()->cancelTask($this->getTaskId());
	}
}
