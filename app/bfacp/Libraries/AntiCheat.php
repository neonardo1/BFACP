<?php namespace BFACP\Libraries;

use BFACP\Battlefield\Player;
use BFACP\Libraries\Battlelog\BPlayer;
use Illuminate\Support\Facades\Cache;
use MainHelper;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AntiCheat
{
    /**
     * @var BFACP\Libraries\Battlelog\BPlayer
     */
    public $battlelog;

    /**
     * Player Object
     * @var BFACP\Battlefield\Player
     */
    public $player;

    /**
     * Guzzle Client
     * @var GuzzleHttp\Client
     */
    protected $guzzle;

    /**
     * Stores the weapons with their damages
     * @var array
     */
    public $weapons = [];

    /**
     * Weapons that were triggered by the Anti-Cheat System
     * @var array
     */
    public $triggered = [];

    /**
     * Name of game
     * @var string
     */
    public $game = '';

    /**
     * Categorys allowed to be parsed
     * @var array
     */
    private $allowedCategorys = [
        'BF3'  => ['carbines', 'machine_guns', 'assault_rifles', 'sub_machine_guns', 'handheld_weapons'],
        'BF4'  => ['carbines', 'lmgs', 'assault_rifles', 'pdws', 'handguns', 'shotguns'],
        'BFHL' => [
            'assault_rifles',
            'ar_standard',
            'sr_standard',
            'br_standard',
            'handguns',
            'pistols',
            'machine_pistols',
            'revolvers',
            'shotguns',
            'smg_mechanic',
            'sg_enforcer',
            'smg'
        ]
    ];

    /**
     * Trigger values
     * @var array
     */
    private $triggers = [
        'DPS'   => 60,
        'HKP'   => 40,
        'KPM'   => 4.5,
        'Kills' => 50
    ];

    public function __construct(Player $player)
    {
        $this->battlelog = new BPlayer($player);
        $this->player    = $player;
        $this->guzzle    = \App::make('GuzzleHttp\Client');
        $this->game      = strtoupper($this->player->game->Name);
        $this->fetchWeaponDamages();
    }

    /**
     * Returns a array of triggered weapons
     * @return array
     */
    public function get()
    {
        return $this->triggered;
    }

    /**
     * Parse the battlelog weapons list
     * @param  array  $weapons
     * @return $this
     */
    public function parse($weapons)
    {
        if (!array_key_exists($this->game, $this->weapons)) {
            throw new HttpException(500, sprintf('The game "%s" is not supported.', $this->game));
        }

        foreach ($weapons as $weapon) {
            $category = str_replace(' ', '_', strtolower(trim($weapon['category'])));

            if (!in_array($category, $this->allowedCategorys[$this->game]) ||
                !array_key_exists($category, $this->weapons[$this->game]) ||
                !array_key_exists($weapon['slug'], $this->weapons[$this->game][$category]) ||
                $this->weapons[$this->game][$category][$weapon['slug']]['max'] >= $this->triggers['DPS']) {
                continue;
            }

            $status = [
                'DPS' => false,
                'HKP' => false,
                'KPM' => false
            ];

            $_weaponDPS = $this->weapons[$this->game][$category][$weapon['slug']];

            $DPSDiff = 1 - MainHelper::divide(($_weaponDPS['max'] - $weapon['dps']), $_weaponDPS['max']);

            // Check if the weapon has been used with a damage mod
            if ($DPSDiff > 1.5 && $weapon['kills'] >= $this->triggers['Kills']) {
                $status['DPS'] = true;
            }

            // Check if the weapon has a high headshot to kill ratio in percentages
            if ($weapon['hskp'] >= $this->triggers['HKP'] && $weapon['kills'] >= $this->triggers['Kills']) {
                $status['HKP'] = true;
            }

            // Check if the weapon has a high kill per minute
            if ($weapon['kpm'] >= $this->triggers['KPM'] && $weapon['kills'] >= $this->triggers['Kills']) {
                $status['KPM'] = true;
            }

            // If either DPS, KPM, or HKP get triggered add the weapon to the triggered weapons list
            if ($status['DPS'] || $status['KPM'] || $status['HKP']) {
                $this->triggered[] = $weapon+['triggered' => $status];
            }
        }

        return $this;
    }

    /**
     * Fetchs the weapon damages from GitHub and caches it for 24 hours (1 day)
     * @return mixed
     */
    private function fetchWeaponDamages()
    {
        $this->weapons = Cache::remember('acs.weapons', 60 * 24, function () {
            $request = $this->guzzle->get('https://raw.githubusercontent.com/AdKats/AdKats/master/adkatsblweaponstats.json');
            $response = $request->json();
            return $response;
        });

        return;
    }
}
