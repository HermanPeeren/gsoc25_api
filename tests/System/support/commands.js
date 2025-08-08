// Custom commands for Joomla CCM testing

// Login command for Joomla administrator
Cypress.Commands.add('doAdministratorLogin', (username, password) => {
  const user = username || Cypress.env('username');
  const pass = password || Cypress.env('password');
  
  cy.visit('/administrator/');
  cy.get('#mod-login-username').type(user);
  cy.get('#mod-login-password').type(pass);
  cy.get('#btn-login-submit').click();
});

// Command to click toolbar buttons in Joomla admin
Cypress.Commands.add('clickToolbarButton', (buttonText) => {
  cy.get('.btn-toolbar').contains(buttonText).click();
});

// Command to check for Joomla system messages
Cypress.Commands.add('checkForSystemMessage', (messageText, options = {}) => {
  const mergedOptions = { timeout: 120000, ...options };
  cy.get('.alert-message, .alert-success', mergedOptions).should('contain', messageText);
});

function createInsertQuery(table, values) {
  let query = `INSERT INTO #__${table} (\`${Object.keys(values).join('\`, \`')}\`) VALUES (:${Object.keys(values).join(',:')})`;

  Object.keys(values).forEach((variable) => {
    query = query.replace(`:${variable}`, `'${values[variable]}'`);
  });

  return query;
}

// Command to create a CMS entry via database
Cypress.Commands.add('db_createCms', (cmsData) => {
  const defaultCmsOptions = {
    name: 'Test CMS',
  };

  const cms = { ...defaultCmsOptions, ...cmsData };

  return cy.task('queryDB', createInsertQuery('ccm_cms', cms)).then(async (info) => {
    cms.id = info.insertId;
    return cms;
  });
});
