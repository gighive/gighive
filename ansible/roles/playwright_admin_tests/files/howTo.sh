cat tests/.env
#vi tests/.env
export NVM_DIR="$HOME/.nvm" && source "$NVM_DIR/nvm.sh" && nvm use 20
npx playwright test
