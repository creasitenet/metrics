<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:user:create', description: 'Create a user allowed to sign in.')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password (12 characters minimum)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $password = (string) $input->getOption('password');
        $errors = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
        if (\count($errors) > 0 || \strlen($password) < 12) {
            $output->writeln('<error>Invalid email or password shorter than 12 characters.</error>');

            return Command::INVALID;
        }
        if (null !== $this->users->findOneBy(['email' => $email])) {
            $output->writeln('<error>A user with this email already exists.</error>');

            return Command::FAILURE;
        }

        $user = new User($email, '');
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $output->writeln(\sprintf('<info>User %s created.</info>', $email));

        return Command::SUCCESS;
    }
}
