######################### INCLUSION LIB ##########################
BASE_DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib --no-cache -O ${BASE_DIR}/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath ${BASE_DIR}/..)")
#LANG_DEP=en
. ${BASE_DIR}/dependance.lib
##################################################################
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/pyenv.lib --no-cache -O ${BASE_DIR}/pyenv.lib &>/dev/null
. ${BASE_DIR}/pyenv.lib
##################################################################
echo ${BASE_DIR}/requirements.txt
######################### INSTALLATION ############################
pre
step 5 "Clean apt"
try apt-get clean
step 10 "Update apt"
try apt-get update

if [ -d ${BASE_DIR}/venv ]; then
  step 15 "Suppression du dossier existant: VENV"
    try rm -rf ${BASE_DIR}/venv
fi

autoSetupVenv

step 80 "Install the required python packages"

try ${VENV_DIR}/bin/python3 -m pip install  -r ${BASE_DIR}/requirements.txt 

step 90 "Summary of installed packages"
try chown -R www-data:www-data ${BASE_DIR}
REAL_PYTHON=$(readlink -f ${VENV_DIR}/bin/python3)
try setcap cap_net_bind_service=+ep ${REAL_PYTHON}
try getcap ${REAL_PYTHON}
${VENV_DIR}/bin/python3 -m pip freeze

post
