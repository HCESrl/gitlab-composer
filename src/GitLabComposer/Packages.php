<?php

namespace GitLabComposer;


use Gitlab\Client;
use Gitlab\Exception\RuntimeException;


class Packages implements \JsonSerializable
{
	
	/**
	 * @var array
	 */
	protected static $acceptedMethods = [ 'ssh', 'http' ];
	
	/**
	 * @var Client
	 */
	protected $client;
	
	/**
	 * @var string
	 */
	protected $method = 'ssh';
	
	/**
	 * @var string
	 */
	protected $cachePath = null;
	
	
	/**
	 * @var array
	 */
	protected $groups = [];
	
	
	/**
	 * @var array
	 */
	protected $projects = [];
	
	
	/**
	 * @var int
	 */
	protected $lastActivity = 0;
	
	
	/**
	 * @param string $endpoint
	 * @param string $authToken
	 * @param string $method
	 */
	public function __construct ( $endpoint, $authToken, $method = 'ssh' )
	{
		$this->client = Client::create ( $endpoint );
		$this->client->authenticate ( $authToken, Client::AUTH_URL_TOKEN );
		
		if ( in_array ( $method, static::$acceptedMethods ) )
		{
			$this->method = $method;
		}
	}
	
	
	/**
	 * @param string $path
	 * @return Packages
	 */
	public function setCachePath ( $path )
	{
		$this->cachePath = rtrim ( $path, '/' );
		
		return $this;
	}
	
	
	/**
	 * @param string|array $groups
	 * @return Packages
	 */
	public function addGroup ( $groups )
	{
		$groups = is_array ( $groups ) ? $groups : func_get_args ();
		
		$this->groups = array_merge ( $this->groups, $groups );
		
		return $this;
	}
	
	
	/**
	 * @param string|array $projects
	 * @return Packages
	 */
	public function addProject ( $projects )
	{
		$projects = is_array ( $projects ) ? $projects : func_get_args ();
		
		$this->projects = array_merge ( $this->projects, $projects );
		
		return $this;
	}
	
	
	/**
	 * @return int
	 */
	public function lastActivity ()
	{
		return $this->lastActivity;
	}
	
	
	/**
	 * Caching layer on top of $fetch_refs
	 * Uses last_activity_at from the $project array, so no invalidation is needed
	 *
	 * @param array $project
	 * @return array|false
	 */
	protected function loadProjectData ( $project )
	{
		if ( $this->cachePath )
		{
			$lastActivity = strtotime ( $project[ 'last_activity_at' ] );
			
			$file = "{$this->cachePath}/{$project['path_with_namespace']}.json";
			
			if ( ! is_dir ( dirname ( $file ) ) )
			{
				mkdir ( dirname ( $file ), 0777, true );
			}
			
			if ( file_exists ( $file ) and filemtime ( $file ) >= $lastActivity and filesize ( $file ) )
			{
				return json_decode ( file_get_contents ( $file ), true );
			}
		}
		
		if ( $data = $this->fetchProjectVersions ( $project ) )
		{
			if ( $this->cachePath )
			{
				file_put_contents ( $file, json_encode ( $data ) );
				
				touch ( $file, $lastActivity );
			}
			
			return $data;
		}
		
		return false;
	}
	
	
	/**
	 * @return array
	 */
	protected function fetchAllProjects ()
	{
		$allProjects = [];
		
		$groups = $this->client->groups ();
		foreach ( $groups->all ( array ( 'page' => 1, 'per_page' => 100 ) ) as $group )
		{
			if ( count ( $this->groups ) and ! in_array ( $group[ 'full_path' ], $this->groups, true ) )
			{
				continue;
			}
			
			$page = 1;
			do
			{
				$projects = $groups->projects ( $group[ 'id' ], array ( 'page' => $page, 'per_page' => 100 ) );
				
				foreach ( $projects as $project )
				{
					$this->lastActivity = max ( $this->lastActivity, strtotime ( $project[ 'last_activity_at' ] ) );
					$allProjects[] = $project;
				}
				
				$page++;
			} while ( count ( $projects ) );
		}
		
		return $allProjects;
	}
	
	
	/**
	 * Retrieves some information about a project for all refs
	 * @param array $project
	 * @return array
	 */
	protected function fetchProjectVersions ( $project )
	{
		$versions = [];
		
		$name = $project[ 'path_with_namespace' ];
		try
		{
			$repos = $this->client->repositories ();
			
			$branches = array_merge ( $repos->branches ( $project[ 'id' ] ), $repos->tags ( $project[ 'id' ] ) );
			
			foreach ( $branches as $ref )
			{
				foreach ( $this->fetchProjectVersion ( $project, $ref ) as $version => $data )
				{
					$versions[ $version ] = $data;
					
					if ( $version === 'dev-master' )
					{
						$name = $data[ 'name' ];
					}
				}
			}
		} catch ( RuntimeException $e )
		{
			// The repo has no commits — skipping it.
		}
		
		return compact ( 'name', 'versions' );
	}
	
	
	/**
	 * Retrieves some information about a project for a specific ref
	 *
	 * @param array $project
	 * @param array $ref commit id
	 * @return array   [$version => ['name' => $name, 'version' => $version, 'source' => [...]]]
	 */
	protected function fetchProjectVersion ( $project, $ref )
	{
		if ( preg_match ( '/^v?\d+\.\d+(\.\d+)*(\-(dev|patch|alpha|beta|RC)\d*)?$/', $ref[ 'name' ] ) )
		{
			$version = $ref[ 'name' ];
		} else
		{
			$version = 'dev-' . $ref[ 'name' ];
		}
		
		if ( ( $data = $this->fetchProjectComposer ( $project, $ref[ 'commit' ][ 'id' ] ) ) !== false )
		{
			$data[ 'version' ] = $version;
			$data[ 'source' ] = array (
				'url'       => $project[ $this->method . '_url_to_repo' ],
				'type'      => 'git',
				'reference' => $ref[ 'commit' ][ 'id' ],
			);
			
			return [ $version => $data ];
		}
		return [];
	}
	
	
	/**
	 * Retrieves some information about a project's composer.json
	 *
	 * @param array  $project
	 * @param string $ref commit id
	 * @return array|false
	 */
	protected function fetchProjectComposer ( $project, $ref )
	{
		try
		{
			$composer = $this->client->repositoryFiles ()
									 ->getFile ( $project[ 'id' ], 'composer.json', $ref );
			
			if ( ! isset( $composer[ 'content' ] ) )
			{
				return false;
			}
			
			$composer = json_decode ( base64_decode ( $composer[ 'content' ] ), true );
			
			if ( empty( $composer[ 'name' ] )/* || strcasecmp ( $composer[ 'name' ], $project[ 'path_with_namespace' ] ) !== 0 */ )
			{
				return false; // packages must have a name and must match
			}
			
			return $composer;
		} catch ( RuntimeException $e )
		{
			return false;
		}
	}
	
	
	/**
	 * @return bool|int
	 */
	protected function cacheLastModification ()
	{
		$cacheFile = "{$this->cachePath}/packages.json";
		
		if ( file_exists ( $cacheFile ) )
		{
			return filemtime ( $cacheFile );
		}
		
		return false;
	}
	
	
	/**
	 * @return bool
	 */
	public function isModified ()
	{
		$since = false;
		if ( isset( $_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] ) and $_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] )
		{
			$since = strtotime ( $_SERVER[ 'HTTP_IF_MODIFIED_SINCE' ] );
		}
		
		if ( $since and ( $lastModification = $this->cacheLastModification () ) and $since >= $lastModification )
		{
			return true;
		}
		
		return false;
	}
	
	
	/**
	 * @return array
	 */
	protected function getData ()
	{
		$cacheFile = "{$this->cachePath}/packages.json";
		
		$projects = $this->fetchAllProjects ();
		
		if ( $this->cachePath and file_exists ( $cacheFile ) and filemtime ( $cacheFile ) > $this->lastActivity )
		{
			$this->lastActivity = filemtime ( $cacheFile );
			return json_decode ( file_get_contents ( $cacheFile ), true );
		}
		
		$packages = [];
		foreach ( $projects as $project )
		{
			if ( $package = $this->loadProjectData ( $project ) )
			{
				if ( count ( $this->projects ) and ! in_array ( $package[ 'name' ], $this->projects ) )
				{
					continue;
				}
				$packages[ $package[ 'name' ] ] = $package[ 'versions' ];
			}
		}
		$packages = [ 'packages' => array_filter ( $packages ) ];
		
		if ( $this->cachePath )
		{
			file_put_contents ( $cacheFile, json_encode ( $packages ) );
		}
		
		return $packages;
	}
	
	
	/**
	 * @return array
	 */
	public function jsonSerialize ()
	{
		return $this->getData ();
	}
	
	
	/**
	 * @return void
	 */
	public function render ()
	{
		if ( $this->isModified () )
		{
			header ( 'HTTP/1.0 304 Not Modified' );
			die;
		}
		
		header ( 'Content-Type: application/json' );
		header ( 'Last-Modified: ' . gmdate ( 'r', $this->lastActivity () ) );
		header ( 'Cache-Control: max-age=0' );
		echo json_encode ( $this->jsonSerialize () );
		die;
	}
	
	
}