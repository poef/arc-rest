<?php

/*
TODO
- use the generator result handler to filter results
*/

class secureStore {
	use \arc\traits\Proxy;

	public function __construct($grantsTree, $store) {
		$this->target = $store;
		$this->grantsTree = $grantsTree;
	}

	public function cd($path) {
		return new self($this->grantsTree->cd($path), $this->target->cd($path));
	}

	public function find($query, $path='') {
		if (!$this->grantsTree->cd($path)->check('read')) {
			throw new \arc\AuthenticationError('Access denied');
		}
		return array_filter(
            $this->target->find($query, $path),
            function($childNode) {
                return $this->grantsTree->cd($childNode->path)->check('read');
            }
        );
	}

	public function get($path='') {
		if (!$this->grantsTree->cd($path)->check('read')) {
			throw new \arc\AuthenticationError('Access denied');
		}
		return $this->target->get($path);
	}

	public function ls($path='') {
		return array_filter(
            $this->target->ls($path),
            function($childNode) {
                return $this->grantsTree->cd($childNode->name)->check('read');
            }
        );	
	}

	public function parents($path='', $top='/') {
		if (!$this->grantsTree->cd($path)->check('read')) {
			throw new \arc\AuthenticationError('Access denied');
		}
		return array_filter(
            $this->target->parents($path, $top),
            function($childNode) {
                return $this->grantsTree->cd($childNode->path)->check('read');
            }
        );			
	}

	public function exists($path='') {
		if (!$this->grantsTree->cd($path)->check('read')) {
			throw new \arc\AuthenticationError('Access denied');
		}
		return $this->target->exists($path);	
	}

	public function save($data, $path='') {
		if (!$this->target->exists($path)) {
			// new object, so use create grant
			if (!$this->grantsTree->cd($path)->check('create')) {
				throw new \arc\AuthenticationError('Access denied');
			}
		} else {
			// existing object, so user update grant
			if (!$this->grantsTree->cd($path)->check('update')) {
				throw new \arc\AuthenticationError('Access denied');
			}
		}
		return $this->target->save($data, $path);
	}

	public function delete($path='') {
		if (!$this->grantsTree->cd($path)->check('delete')) {
			throw new \arc\AuthenticationError('Access denied');
		}
		return $this->target->delete($path);
	}

}