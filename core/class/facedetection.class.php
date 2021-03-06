<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
include_file('core', 'FaceDetector', 'class', 'facedetection');

class facedetection extends eqLogic {
	public function postSave() {
		self::AddCommande($this,'Face Detection','facedetection',"info", 'binary','');
		self::AddCommande($this,'Snapshot','snapshots',"action", 'other','');
		self::AddCommande($this,'Snapshot avec detection','snapshotfacedetect',"action", 'other',''); 
	}
	public static function dependancy_info() {
		$return = array();
		$return['log'] = 'facedetection_update';
		$return['progress_file'] = '/tmp/compilation_facedetection_in_progress';
		if (exec('dpkg -s php5-gd | grep -c "Status: install"') ==1)
				$return['state'] = 'ok';
		else
			$return['state'] = 'nok';
		return $return;
	}
	public static function dependancy_install() {
		if (file_exists('/tmp/compilation_facedetection_in_progress')) {
			return;
		}
		log::remove('facedetection_update');
		$cmd = 'sudo apt-get install php5-gd';
		$cmd .= ' >> ' . log::getPathToLog('facedetection_update') . ' 2>&1 &';
		exec($cmd);		
	}
	public static function deamon_info() {
		$return = array();
		$return['log'] = 'facedetection';	
		$return['launchable'] = 'nok';
		$return['state'] = 'nok';
		$cron = cron::byClassAndFunction('facedetection', 'FaceAnalyse');
		if(is_object($cron) && $cron->running())
			$return['state'] = 'ok';
		return $return;
	}
	public static function deamon_start($_debug = false) {
		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') 
			return;
		log::remove('facedetection');
		self::deamon_stop();
		$cron = cron::byClassAndFunction('facedetection', 'FaceAnalyse');
		if (!is_object($cron)) {
			$cron = new cron();
			$cron->setClass('facedetection');
			$cron->setFunction('FaceAnalyse');
			$cron->setEnable(1);
			$cron->setDeamon(1);
			$cron->setSchedule('* * * * *');
			$cron->setTimeout('999999');
			$cron->save();
		}
		$cron->start();
		$cron->run();
	}
	public static function deamon_stop() {
		$cron = cron::byClassAndFunction('facedetection', 'FaceAnalyse');
		if (is_object($cron)) {
			$cron->stop();
			$cron->remove();
		}
	}
	public static function AddCommande($eqLogic,$Name,$_logicalId,$Type="info", $SubType='binary',$icone) 
	{
		$Commande = $eqLogic->getCmd(null,$_logicalId);
		if (!is_object($Commande))
		{
			$Commande = new facedetectionCmd();
			$Commande->setId(null);
			$Commande->setName($Name);
			$Commande->setLogicalId($_logicalId);
			$Commande->setEqLogic_id($eqLogic->getId());
			$Commande->setType($Type);
			$Commande->setSubType($SubType);
			if ($icone!='')
				$Commande->setDisplay('icon',$icone);
			$Commande->save();
		}
		//$Commande->setEventOnly(1);
		return $Commande;
	}
	public static function FaceAnalyse() 
	{
		while(true)
		{
			foreach(eqLogic::byType('facedetection') as $Cameras)
			{
				//log::add('facedetection', 'debug', 'Lancement d\'une détéction sur la camera '.$Cameras->getName());
				//$Cameras->execute();
			}
		}
	}
}

class facedetectionCmd extends cmd {
	public function Snapshot($camurl, $image) {
		log::add('facedetection', 'debug', 'Telechargement du flux : '.$camurl);
		$f = fopen($camurl,"r") ;
		if(!$f)
			throw new Exception(__('Impossible de ce connecter a: '.$url, __FILE__));
		else
		{
			//**** URL OK
			while (substr_count($r,"Content-Length") != 2) 
				$r.=fread($f,512);
			$start=stripos($r,"Content-Length");
			$frame=substr($r,$start);
			$start=stripos($frame,"\n")+3;
			$frame=substr($frame,$start);
			$stop=stripos($frame,'--myboundary')-2;
			$frame=substr($frame,0,$stop);
			if(!$fp = fopen($image,'w'))
				throw new Exception(__('Impossible d\'ouvrir le dossier', __FILE__));
			fwrite($fp, $frame); 
			fclose($fp);   
		}
		fclose($f);
	}
	public function FaceDetect($image) {
		
		$detector = new FaceDetector('detection.dat');
		log::add('facedetection', 'debug', 'Début de l\'analyse: '.$image);
		$detector->faceDetect($image);
		$len=count($detector->getFace())/3;
		log::add('facedetection', 'debug', $len.' visage(s) détecté');
		$detector->toJpeg($image);//Encadre dans la photo le visage
		if ($len==0)
		{
			$this->setCollectDate('');
			$this->event(0);
			$this->save();
		}
		else
		{
			$this->setCollectDate('');
			$this->event(1);
			$this->save();
		}
		return $len.' visage(s) détecté';
	}
    public function execute($_options = array()) {
		$EqLogic=$this->getEqLogic();
		$Camera=camera::byId($EqLogic->getConfiguration('snapshots'));
		
		if (netMatch('192.168.*.*', getClientIp())) {
			$protocole = 'protocole';
		} else {
			$protocole = 'protocoleExt';
		}
		$camurl=$Camera->getUrl($Camera->getConfiguration('urlStream'), '', $protocole);
		switch($this->getLogicalId())
		{
			case 'facedetection':
				$image=dirname(__FILE__) . '/../../../../tmp/analyse.jpg';
				self::Snapshot($camurl,$image);
				self::FaceDetect($image);
			break;
			case 'snapshots':
				$image=dirname(__FILE__) .'/../../ressources/FaceDetection/Snapshot_'.date("YmdHis").'.jpg';
				self::Snapshot($camurl,$image);
			break;
			case 'snapshotfacedetect':
				$image=dirname(__FILE__) .'/../../ressources/Snapshots/Snapshot_'.date("YmdHis").'.jpg';
				self::Snapshot($camurl,$image);
				self::FaceDetect($image);
			break;
			case 'snapshotdir':
			break;
			
		}
    }

    /*     * **********************Getteur Setteur*************************** */
}

?>
