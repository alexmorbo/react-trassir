<?php

namespace AlexMorbo\React\Trassir\Dto;

use AlexMorbo\Trassir\Trassir;

class Instance
{
    private int $id;
    private ?string $name = null;
    private string $ip;
    private int $httpPort;
    private int $rtspPort;
    private string $login;
    private string $password;

    public function __construct(array $instanceData, private Trassir $trassir)
    {
        $this->id = $instanceData['id'];
        $this->name = $instanceData['name'];
        $this->ip = $instanceData['ip'];
        $this->httpPort = $instanceData['http_port'];
        $this->rtspPort = $instanceData['rtsp_port'];
        $this->login = $instanceData['login'];
        $this->password = $instanceData['password'];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getHttpPort(): int
    {
        return $this->httpPort;
    }

    public function getRtspPort(): int
    {
        return $this->rtspPort;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getTrassir(): Trassir
    {
        return $this->trassir;
    }
}