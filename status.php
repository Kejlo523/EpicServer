<?php

// Be sure to enable query support in your server/bungeecord config! (enable-query setting)

$configServerAddress = 'maxrp.pl'; // your server hostname or ip address
$configServerPort = 25565; // your server port
$configServerName = 'MaxMC'; // your server name
$configServerWebsite = ''; // your server website URL, set to '' if you don't have one
$configServerLogo = 'https://cdn.discordapp.com/attachments/762005089324892250/923383488852410478/logo2.png'; // your server LOGO (URL of image), set to '' if you don't have one
$configServerShowAddress = true; // can be true or false
$configServerShowPlayerCount = true; // can be true or false
$configServerShowPlayerNames = true; // can be true or false
$configServerShowVersion = true; // can be true or false
$configServerShowPlugins = false; // can be true or false (this generally won't work with bungeecord)
$configServerShowMotd = true; // can be true or false

/**********************************************************************************************************************\
|
|       DO NOT EDIT ANYTHING AFTER THIS LINE UNLESS YOU'RE ABSOLUTELY SURE YOU KNOW WHAT YOU'RE DOING !!!
|
\**********************************************************************************************************************/

if(isset($configServerAddress, $configServerPort, $configServerName)) {} else { die('Error: Invalid configuration!'); }

if(!isset($configServerWebsite) || trim($configServerWebsite) == '')
    $configServerWebsite = null;
if(!isset($configServerLogo) || trim($configServerLogo) == '')
    $configServerLogo = null;
if(!is_bool($configServerShowAddress))
    $configServerShowAddress = true;
if(!is_bool($configServerShowPlayerCount))
    $configServerShowPlayerCount = true;
if(!is_bool($configServerShowPlayerNames))
    $configServerShowPlayerNames = true;
if(!is_bool($configServerShowVersion))
    $configServerShowVersion = true;
if(!is_bool($configServerShowPlugins))
    $configServerShowPlugins = false;
if(!is_bool($configServerShowMotd))
    $configServerShowMotd = true;

/**********************************************************************************************************************/

class MinecraftQuery
{
	private $socket;
	private $address;
	private $port;
	private $timeout;
	private $error = null;

	private $Info = null;
	private $Players = array();

	public function __construct($address, $port = 25565, $timeout = 3, $resolveSRV = true)
	{
		if(!is_int($port) || $port < 1 || $port > 65535)
			$port = 25565;

		if(!is_int($timeout) || $timeout < 1 || $timeout > 60)
			$timeout = 3;

		if(!is_bool($resolveSRV))
			$resolveSRV = true;

		$this->address = trim($address);
		$this->port = $port;
		$this->timeout = $timeout;

		if($resolveSRV)
			$this->resolveSRV();

		$errorReporting = error_reporting();
		error_reporting(0);
		$this->socket = @fsockopen('udp://'.$this->address, $this->port, $errno, $errstr, $this->timeout);
		error_reporting($errorReporting);

		if($errno || $this->socket === false)
		{
			$this->error = 'Could not create socket.';
			return;
		}

		stream_set_timeout($this->socket, $this->timeout);
		stream_set_blocking($this->socket, true);

		$challenge = $this->getChallenge();
		if($this->error != null)
		{
			fclose($this->socket);
			return;
		}

		$this->GetStatus($challenge);
		if($this->error != null)
		{
			fclose($this->socket);
			return;
		}

		fclose($this->socket);
	}

	private function resolveSRV()
	{
		if(ip2long($this->address) !== FALSE)
			return;

		$result = dns_get_record('_minecraft._tcp.'.$this->address, DNS_SRV);
		if(count($result) > 0)
		{
			if(isset($result[0]['target']))
			{
				$this->address = $result[0]['target'];
			}

			if(isset($result[0]['port']))
			{
				$this->port = $result[0]['port'];
			}
		}
	}

	private function getChallenge()
	{
		$Data = $this->writeData(0x09);

		if($Data === false)
		{
			$this->error = 'Failed to receive challenge.';
			return;
		}

		return Pack('N', $Data);
	}

	private function writeData($Command, $Append = "")
	{
		$Command = Pack('c*', 0xFE, 0xFD, $Command, 0x01, 0x02, 0x03, 0x04).$Append;
		$Length = StrLen($Command);

		if($Length !== FWrite($this->socket, $Command, $Length))
		{
			$this->error = ("Failed to write on socket.");
			return false;
		}

		$Data = fread($this->socket, 4096);

		if($Data === false)
		{
			$this->error = ("Failed to read from socket.");
			return false;
		}

		if(StrLen($Data) < 5 || $Data[0] != $Command[2])
		{
			return false;
		}

		return SubStr($Data, 5);
	}

	private function GetStatus($Challenge)
	{
		$Data = $this->writeData(0x00, $Challenge.Pack('c*', 0x00, 0x00, 0x00, 0x00));
		if(!$Data)
		{
			$this->error = 'Failed to receive status.';
			return;
		}

		$Last = '';
		$Info = Array();

		$Data = SubStr($Data, 11); // splitnum + 2 int
		$Data = Explode("\x00\x00\x01player_\x00\x00", $Data);

		if(Count($Data) !== 2)
		{
			$this->error = 'Failed to parse server\'s response.';
			return;
		}

		$Players = SubStr($Data[1], 0, -2);
		$Data = Explode("\x00", $Data[0]);

		$Keys = Array(
			'hostname' => 'HostName',
			'gametype' => 'GameType',
			'version' => 'Version',
			'plugins' => 'Plugins',
			'map' => 'Map',
			'numplayers' => 'Players',
			'maxplayers' => 'MaxPlayers',
			'hostport' => 'HostPort',
			'hostip' => 'HostIp',
			'game_id' => 'GameName'
		);

		foreach($Data as $Key => $Value)
		{
			if(~$Key & 1)
			{
				if(!Array_Key_Exists($Value, $Keys))
				{
					$Last = false;
					continue;
				}

				$Last = $Keys[$Value];
				$Info[$Last] = '';
			}
			else if($Last != false)
			{
				$Info[$Last] = mb_convert_encoding($Value, 'UTF-8', 'ASCII');
			}
		}

		// integers
		$Info['Players'] = IntVal($Info['Players']);
		$Info['MaxPlayers'] = IntVal($Info['MaxPlayers']);
		$Info['HostPort'] = IntVal($Info['HostPort']);

		// plugins
		if($Info['Plugins'])
		{
			$Data = Explode(": ", $Info['Plugins'], 2);
			$Info['RawPlugins'] = $Info['Plugins'];
			$Info['Software'] = $Data[0];

			if(Count($Data) == 2)
				$Info['Plugins'] = Explode("; ", $Data[1]);
		}
		else
		{
			$Info['Software'] = 'Vanilla';
		}

		if(isset($Info['HostName']))
		{
			$Info['HostNameHTML'] = $this->parseColors($Info['HostName']);
			$Info['HostNamePlain'] = $this->stripColors($Info['HostName']);
		}

		if($Info['Plugins'] == '')
			$Info['Plugins'] = array();

		$this->Info = $Info;

		if(!empty($Players))
		{
			$Players = Explode("\x00", $Players);
			foreach($Players as $key => $player)
				if(trim($player) == '')
					unset($Players[$key]);
			sort($Players);
			$this->Players = $Players;
		}
	}

	public function getInfo()
	{
		return $this->Info;
	}

	public function getPlayers()
	{
		return $this->Players;
	}

	private function parseColors($string)
	{
		$colorCodes = array('§0', '§1', '§2', '§3', '§4', '§5', '§6', '§7', '§8', '§9', '§a', '§b', '§c', '§d', '§e', '§f', '§r', '§k', '§l', '§m', '§n', '§o');
		$styleCodes = array('color:#000000;', 'color:#0000AA;', 'color:#00AA00;', 'color:#00AAAA;', 'color:#AA0000;', 'color:#AA00AA;', 'color:#FFAA00;', 'color:#AAAAAA;', 'color:#555555;', 'color:#5555FF;', 'color:#55FF55;', 'color:#55FFFF;', 'color:#FF5555;', 'color:#FF55FF;', 'color:#FFFF55;', 'color:#FFFFFF;', 'color:#ffffff;font-weight:normal;font-style:normal;text-decoration:none;', '', 'font-weight:bold;', 'text-decoration:line-through;', 'text-decoration:underline;', 'font-style:italic;');
		$open = 0;

		foreach($colorCodes as $key=>$colorCode)
		{
			$open += substr_count($string, $colorCode);
			$string = str_replace($colorCode, '<span style="'.$styleCodes[$key].'">', $string);
		}

		return $string . str_repeat("</span>", $open);
	}

	private function stripColors($string)
	{
		$colorCodes = array('§0', '§1', '§2', '§3', '§4', '§5', '§6', '§7', '§8', '§9', '§a', '§b', '§c', '§d', '§e', '§f', '§r', '§k', '§l', '§m', '§n', '§o');
		$string = str_replace($colorCodes, '', $string);
		return $string;
	}

	public function getError()
	{
		return $this->error;
	}
}

/**********************************************************************************************************************/

echo '<!doctype html>';
echo '<html lang="pl">';
    echo '<head>';
        echo '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">';
        echo '<title>Status '.$configServerName.'</title>';
        echo '<link rel="icon" type="image/gif" href="https://media.discordapp.net/attachments/887976068533870682/924460799722934302/logom2.png?width=467&height=467">';
?>
<style>
@font-face {font-family: "Minecraft"; src: url("//db.onlinewebfonts.com/t/6ab539c6fc2b21ff0b149b3d06d7f97c.eot"); src: url("//db.onlinewebfonts.com/t/6ab539c6fc2b21ff0b149b3d06d7f97c.eot?#iefix") format("embedded-opentype"), url("//db.onlinewebfonts.com/t/6ab539c6fc2b21ff0b149b3d06d7f97c.woff2") format("woff2"), url("//db.onlinewebfonts.com/t/6ab539c6fc2b21ff0b149b3d06d7f97c.woff") format("woff"), url("//db.onlinewebfonts.com/t/6ab539c6fc2b21ff0b149b3d06d7f97c.ttf") format("truetype"), url("//db.onlinewebfonts.com/t/6ab539c6fc2b21ff0b149b3d06d7f97c.svg#Minecraft") format("svg"); }
body{background-image: url('https://cdn.discordapp.com/attachments/722575391348228126/924456591091195904/stone.png'); background-size: 5%;}
h5{padding-bottom: 20px;}
a{font-family:"Minecraft";font-size: 18px; color:green;}
.pre{background-image: url('https://cdn.discordapp.com/attachments/759586305196818444/880247284066050058/brown-dirt-minecraft-pattern_thumb.png');}
pre{width:100%;background-color: rgb(0, 0, 0,0.5);padding:15px 20px;font-family:"Minecraft";font-size: 20px;margin:0 auto;}
.container{width:900px;padding:20px 20px 0 20px;margin: 0 auto 20px auto;border-radius: 5px;}
.head {width: 100%;border-bottom: 2px solid #ddd;background-image: url('https://cdn.discordapp.com/attachments/887976068533870682/923885916634890270/d4hxmb7-37801a9c-024f-4e39-96f1-108f32aefa8e.png');margin-bottom: 40px; background-size: cover;}
.head .container{margin-bottom: 0;border:0;border-radius:0;padding:20px 20px 60px 20px;}
.headbar img{max-height: 100px;}
.player{width: 170px;text-align: center}
.textright{text-align: right; color:green; text-shadow: -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000; font-family:"Minecraft";font-size: 30px;}
.textcenter{text-align: center;}
.paddingbottom20{padding-bottom: 20px; background-color: rgb(163, 163, 163 ,0.7);}
@media (max-width: 575.98px) { .container{width:520px;} }
@media (min-width: 576px) and (max-width: 767.98px) { .container{width:600px;} }
@media (min-width: 768px) and (max-width: 991.98px) { .container{width:800px;} }
@media (min-width: 992px) and (max-width: 1199.98px) { .container{width:1000px;} }
@media (min-width: 1200px) { .container{width:1200px;} }
</style>
<?php
echo '</head>';
    echo '<body>';

        echo '<div class="head">';
            echo '<div class="container">';
                echo '<div class="row">';
                    echo '<div class="col-md-6 headbar">'.($configServerWebsite != null ? '<a href="'.$configServerWebsite.'">' : '').($configServerLogo != null ? '<img class="img-fluid" src="'.$configServerLogo.'" alt="'.$configServerName.'">' : '&nbsp;').($configServerWebsite != null ? '</a>' : '').'</div>';
                    echo '<div class="col-md-6 headbar textright">'.($configServerWebsite != null ? '<a href="'.$configServerWebsite.'">' : '').$configServerName.($configServerWebsite != null ? '</a>' : '').'</div>';
                echo '</div>';
            echo '</div>';
        echo '</div>';

        $MinecraftQuery = new MinecraftQuery($configServerAddress, $configServerPort, 3);
        $MinecraftLobby = new MinecraftQuery($configServerAddress, 25566, 3);
        $MinecraftSurv = new MinecraftQuery($configServerAddress, 25567, 3);
        $Minecraftskywars = new MinecraftQuery($configServerAddress, 25568, 3);
        if($MinecraftQuery->getError() != null)
        {
            echo '<div class="container">';
                echo '<div class="alert alert-danger" role="alert" style="width:40%">Serwer Jest Offline!</div>';
            echo '</div>';
        }
        else
        {
            echo '<div class="container '.($configServerShowAddress || $configServerShowPlayerCount || $configServerShowVersion || $configServerShowPlugins || $configServerShowMotd ? 'paddingbottom20' : '').'">';

                echo '<div class="alert alert-success" role="alert" style="width:20%">Serwer Jest Online!</div>';

                if($configServerShowAddress)
                    echo 'Adres Serwera: <strong>'.$configServerAddress.($configServerPort != 25565 ? ':'.$configServerPort : '').'</strong><br />';
                if($configServerShowPlayerCount && isset($MinecraftQuery->getInfo()['Players'], $MinecraftQuery->getInfo()['MaxPlayers']))
                    echo 'Gracze na serwerze: <strong>'.$MinecraftQuery->getInfo()['Players'].' / '.$MinecraftQuery->getInfo()['MaxPlayers'].'</strong><br />';
                if($configServerShowVersion && isset($MinecraftQuery->getInfo()['Version']))
                    echo 'Dostępne wersje: <strong>'.$MinecraftQuery->getInfo()['Version'].'</strong><br />';
                if($configServerShowPlayerCount && isset($MinecraftLobby->getInfo()['Players'], $MinecraftLobby->getInfo()['MaxPlayers']))
                    echo 'Serwer Lobby: <strong>'.$MinecraftLobby->getInfo()['Players'].' / '.$MinecraftLobby->getInfo()['MaxPlayers'].'</strong><br />';
                if($configServerShowPlayerCount && isset($MinecraftLobby->getInfo()['Players'], $MinecraftLobby->getInfo()['MaxPlayers']))
                    echo 'Serwer Survival: <strong>'.$MinecraftSurv->getInfo()['Players'].' / '.$MinecraftSurv->getInfo()['MaxPlayers'].'</strong><br />';
                if($configServerShowPlayerCount && isset($Minecraftskywars->getInfo()['Players'], $Minecraftskywars->getInfo()['MaxPlayers']))
                    echo 'Serwer Skywars: <strong>'.$Minecraftskywars->getInfo()['Players'].' / '.$Minecraftskywars->getInfo()['MaxPlayers'].'</strong><br />';
                if($configServerShowPlugins && isset($MinecraftQuery->getInfo()['Plugins']) && count($MinecraftQuery->getInfo()['Plugins']) > 0)
                    echo 'Plugins: <strong>'.implode(', ', $MinecraftQuery->getInfo()['Plugins']).'</strong><br />';
                if($configServerShowMotd && isset($MinecraftQuery->getInfo()['HostNameHTML']))
                    echo '<br /><div class="pre"><pre>'.$MinecraftQuery->getInfo()['HostNameHTML'].'</pre></div>';
                if($configServerShowMotd && isset($MinecraftLobby->getInfo()['HostNameHTML']))
                    echo '<br /><div class="pre"><pre>'.$MinecraftLobby->getInfo()['HostNameHTML'].'</pre></div>';
                if($configServerShowMotd && isset($MinecraftSurv->getInfo()['HostNameHTML']))
                    echo '<br /><div class="pre"><pre>'.$MinecraftSurv->getInfo()['HostNameHTML'].'</pre></div>';
                if($configServerShowMotd && isset($Minecraftskywars->getInfo()['HostNameHTML']))
                    echo '<br /><div class="pre"><pre>'.$Minecraftskywars->getInfo()['HostNameHTML'].'</pre></div>';

            echo '</div>';

            if($configServerShowPlayerNames)
			{
				echo '<div class="container paddingbottom20">';
                    echo '<h5 class="textcenter">Gracze Online</h5>';
                    echo '<div class="row justify-content-center">';
                    foreach($MinecraftQuery->getPlayers() as $player)
                    {
                        echo '<div class="col- player"><a href="https://mcnames.net/username/'.$player.'/">'.$player.'</a></div>';
                    }
                    echo '</div>';
				echo '</div>';
			}
        }

        echo '<div class="container paddingbottom20">';
            echo '<div class="textcenter">';
                echo ($configServerWebsite != null ? '<a href="'.$configServerWebsite.'">' : '').$configServerName.($configServerWebsite != null ? '</a>' : '').' Status, Nicki graczy Minecraft <a href="https://mcnames.net/">mcnames.net</a>.';
            echo '</div>';
        echo '</div>';

        echo '<script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>';
        echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>';
        echo '<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>';

    echo '</body>';
echo '</html>';
